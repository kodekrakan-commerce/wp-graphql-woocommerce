<?php
/** Bounded GraphQL account transitions, independent of cart identity. */
namespace WPGraphQL\WooCommerce\Utils;

use GraphQL\Error\ProvidesExtensions;
use GraphQL\Error\UserError;
use GraphQL\Executor\Values;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Type\Definition\AbstractType;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

/** Stable public error; no provider, credential or internal failure details. */
final class Cart_Session_Transition_Error extends UserError implements ProvidesExtensions {
	public function __construct() {
		parent::__construct( __( 'The account transition is not supported in this operation.', 'wp-graphql-woocommerce' ) );
	}
	public function getExtensions(): ?array {
		return [ 'code' => 'WL_CART_SESSION_TRANSITION_INVALID' ];
	}
}

/** One execution permits either ordinary cart work or one confined account transition. */
final class Cart_Session_Operation {
	private $handler;
	private $operation;
	private $schema;
	private $purpose;
	private $response_key;
	private $failed = false;
	private $completed_identity;
	private $starting_identity;

	public function __construct( $handler ) {
		$this->handler = $handler;
		add_action( 'graphql_before_resolve_field', [ $this, 'before_field' ], PHP_INT_MIN, 8 );
		add_action( 'graphql_execute_batch_queries', [ $this, 'reject_batch' ], PHP_INT_MIN, 1 );
		add_action( 'graphql_mutation_response', [ $this, 'mutation_response' ], 0, 6 );
		add_filter( 'graphql_pre_mutate_and_get_payload', [ $this, 'before_mutation' ], PHP_INT_MIN, 6 );
		// Run last so another pre-resolution filter cannot revive the legacy token.
		add_filter( 'graphql_pre_resolve_field', [ $this, 'pre_resolve' ], PHP_INT_MAX, 9 );
	}

	private function reject() {
		$this->failed = true;
		$this->handler->reject_cart_operation();
		throw new Cart_Session_Transition_Error();
	}

	public function reject_batch( $queries ) {
		if ( $this->handler->is_graphql_session() ) {
			$this->reject();
		}
	}

	/** WPGraphQL InstrumentSchema passes all eight arguments here. */
	public function before_field( $source, $args, $context, $info, $resolver, $type_name, $field_key, $field ) {
		if ( ! $this->handler->is_graphql_session() ) {
			return;
		}
		if ( $this->failed || ! $info instanceof ResolveInfo ) {
			$this->reject();
		}
		if ( null === $this->operation ) {
			$this->inspect_operation( $info );
			$this->handler->assert_session_ready();
			$this->starting_identity = (int) get_current_user_id();
			if ( null !== $this->purpose ) {
				$this->handler->detach_for_auth();
			} else {
				$this->handler->prepare_session_token();
				if ( $this->selects_customer_token( $info ) ) {
					$this->handler->prepare_customer_session_token();
				}
				$this->handler->complete_session_preparation();
			}
		}
		if ( $this->operation !== $info->operation || $this->schema !== $info->schema ) {
			$this->reject();
		}
		if ( null !== $this->purpose ) {
			if ( ! $this->handler->is_auth_detached() || ! $this->permits_field( $info ) ) {
				$this->reject();
			}
			if ( null !== $this->completed_identity && $this->completed_identity !== (int) get_current_user_id() ) {
				$this->reject();
			}
		} else {
			$this->handler->assert_session_ready();
		}
	}

	/** Inspect final filtered input before any pre-mutation filter or mutation callback. */
	public function before_mutation( $pre, $mutation_name, $callback, $input, $context, $info ) {
		if ( ! $this->handler->is_graphql_session() ) {
			return $pre;
		}
		// WPGraphQL's hook carries the configured type name (e.g. Login), while
		// its actual root field is lcfirst($mutation_name), including Headless Login.
		$name = is_string( $mutation_name ) ? lcfirst( $mutation_name ) : null;
		if ( $this->failed || ! $info instanceof ResolveInfo || $this->operation !== $info->operation
			|| $this->schema !== $info->schema || 1 !== count( $info->path )
			|| $info->parentType !== $info->schema->getMutationType() || $name !== $info->fieldName
			|| ! is_array( $input ) || $this->starting_identity !== (int) get_current_user_id() ) {
			$this->reject();
		}
		if ( null !== $this->purpose ) {
			if ( $name !== $this->purpose || ! $this->handler->is_auth_detached()
				|| ! $this->permits_field( $info ) || null !== $this->completed_identity
				|| ( 'login' === $name && 'password' !== ( $input['provider'] ?? null ) ) ) {
				$this->reject();
			}
		} else {
			$this->assert_ordinary_input( $name, $input );
			$this->handler->assert_session_ready();
		}
		return $pre;
	}

	/** Interim holds also apply after trusted extensions finish filtering input. */
	private function assert_ordinary_input( $name, array $input ) {
		if ( in_array( $name, [ 'registerCustomer', 'registerUser', 'createAccount', 'forgetSession' ], true ) ) {
			$this->reject();
		}
		if ( 'checkout' === $name && 0 === (int) get_current_user_id()
			&& ( ! empty( $input['account'] ) || \WPGraphQL\WooCommerce\Data\Mutation\Checkout_Mutation::is_registration_required() ) ) {
			$this->reject();
		}
	}

	private function inspect_operation( ResolveInfo $info ) {
		$this->operation = $info->operation;
		$this->schema = $info->schema;
		$type = 'mutation' === $info->operation->operation ? $info->schema->getMutationType() : $info->schema->getQueryType();
		if ( ! $type instanceof ObjectType ) {
			$this->reject();
		}
		$roots = $this->collect( $info->operation->selectionSet, $type, $info );
		$auth = [];
		foreach ( $roots as $key => $nodes ) {
			$name = $nodes[0]->name->value;
			if ( $type === $info->schema->getMutationType() ) {
				$root_args = '__typename' === $name ? [] : Values::getArgumentValues( $type->getField( $name ), $nodes[0], $info->variableValues );
				$this->assert_ordinary_input( $name, $root_args['input'] ?? [] );
			}
			if ( $type === $info->schema->getMutationType() && in_array( $name, [ 'login', 'logout' ], true ) ) {
				$auth[ $key ] = $nodes;
			}
		}
		if ( empty( $auth ) ) {
			return;
		}
		if ( 1 !== count( $auth ) ) {
			$this->reject();
		}
		$this->response_key = (string) key( $auth );
		$nodes = current( $auth );
		$this->purpose = $nodes[0]->name->value;
		foreach ( $roots as $key => $group ) {
			if ( (string) $key !== $this->response_key && '__typename' !== $group[0]->name->value ) {
				$this->reject();
			}
		}
		$field = $type->getField( $this->purpose );
		$arguments = Values::getArgumentValues( $field, $nodes[0], $info->variableValues );
		if ( 'login' === $this->purpose && 'password' !== ( $arguments['input']['provider'] ?? null ) ) {
			$this->reject();
		}
		$payload_type = Type::getNamedType( $field->getType() );
		if ( $payload_type->name !== ( 'login' === $this->purpose ? 'LoginPayload' : 'LogoutPayload' ) ) {
			$this->reject();
		}
		foreach ( $nodes as $node ) {
			$this->inspect_payload( $node->selectionSet, $payload_type, $info, [ $this->purpose ] );
		}
	}

	private function inspect_payload( $selection, $type, ResolveInfo $info, array $path ) {
		foreach ( $this->collect( $selection, $type, $info ) as $nodes ) {
			foreach ( $nodes as $node ) {
				$next = array_merge( $path, [ $node->name->value ] );
				if ( ! $this->permits_path( $next, $type->name ) ) {
					$this->reject();
				}
				if ( null !== $node->selectionSet ) {
					$child = Type::getNamedType( $type->getField( $node->name->value )->getType() );
					if ( ! $child instanceof ObjectType ) {
						$this->reject();
					}
					$this->inspect_payload( $node->selectionSet, $child, $info, $next );
				}
			}
		}
	}

	private function permits_path( array $path, $parent_name ) {
		if ( $path[0] !== $this->purpose ) {
			return false;
		}
		if ( 2 === count( $path ) ) {
			$allowed = 'login' === $this->purpose
				? [ 'authToken', 'authTokenExpiration', 'refreshToken', 'refreshTokenExpiration', 'user', 'customer', 'sessionToken', 'clientMutationId', '__typename' ]
				: [ 'success', 'clientMutationId', '__typename' ];
			return $parent_name === ( 'login' === $this->purpose ? 'LoginPayload' : 'LogoutPayload' ) && in_array( $path[1], $allowed, true );
		}
		if ( 3 === count( $path ) && 'login' === $this->purpose ) {
			if ( 'user' === $path[1] && 'User' === $parent_name ) {
				return in_array( $path[2], [ 'id', 'databaseId', 'name', 'username', '__typename' ], true );
			}
			if ( 'customer' === $path[1] && 'Customer' === $parent_name ) {
				return in_array( $path[2], [ 'id', 'databaseId', 'username', 'firstName', 'lastName', '__typename' ], true );
			}
		}
		return false;
	}

	private function permits_field( ResolveInfo $info ) {
		if ( 1 === count( $info->path ) ) {
			return $info->parentType === $info->schema->getMutationType()
				&& ( '__typename' === $info->fieldName || ( (string) $info->path[0] === $this->response_key && $info->fieldName === $this->purpose ) );
		}
		return (string) $info->path[0] === $this->response_key
			&& null !== $this->completed_identity
			&& $this->permits_path( $info->unaliasedPath, $info->parentType->name );
	}

	/** Verify the callback's completed account identity before any payload field. */
	public function mutation_response( $payload, $input, $unfiltered_input, $context, $info, $mutation_name ) {
		if ( null === $this->purpose ) {
			return;
		}
		if ( $this->failed || ! $info instanceof ResolveInfo || $this->operation !== $info->operation
			|| $this->schema !== $info->schema || ! $this->handler->is_auth_detached()
			|| ! is_string( $mutation_name ) || lcfirst( $mutation_name ) !== $this->purpose
			|| ! $this->permits_field( $info ) || null !== $this->completed_identity ) {
			$this->reject();
		}
		$id = (int) get_current_user_id();
		if ( 'login' === $this->purpose ) {
			if ( $id <= 0 || ! is_array( $payload ) || ! isset( $payload['id'], $payload['user']->ID )
				|| $id !== (int) $payload['id'] || $id !== (int) $payload['user']->ID ) {
				$this->reject();
			}
		} elseif ( 0 !== $id ) {
			$this->reject();
		}
		$this->completed_identity = $id;
	}

	public function pre_resolve( $value, $source, $args, $context, $info, $type_name, $field_key, $field, $resolver ) {
		if ( 'login' === $this->purpose && $info instanceof ResolveInfo && $this->operation === $info->operation
			&& $this->schema === $info->schema && $this->handler->is_auth_detached()
			&& $this->permits_field( $info ) && [ 'login', 'sessionToken' ] === $info->unaliasedPath ) {
			return null;
		}
		return $value;
	}

	/** Expand executable fields using the executor's coerced directives and fragment applicability. */
	private function collect( $selection_set, ObjectType $type, ResolveInfo $info, array &$visited = [] ) {
		$fields = [];
		if ( null === $selection_set ) {
			return $fields;
		}
		foreach ( $selection_set->selections as $node ) {
			$skip = Values::getDirectiveValues( Directive::skipDirective(), $node, $info->variableValues, $info->schema );
			$include = Values::getDirectiveValues( Directive::includeDirective(), $node, $info->variableValues, $info->schema );
			if ( true === ( $skip['if'] ?? false ) || false === ( $include['if'] ?? true ) ) {
				continue;
			}
			if ( $node instanceof FieldNode ) {
				$key = $node->alias->value ?? $node->name->value;
				$fields[ $key ][] = $node;
				continue;
			}
			if ( $node instanceof FragmentSpreadNode ) {
				$name = $node->name->value;
				if ( isset( $visited[ $name ] ) || ! isset( $info->fragments[ $name ] ) ) {
					continue;
				}
				$visited[ $name ] = true;
				$node = $info->fragments[ $name ];
			} elseif ( ! $node instanceof InlineFragmentNode ) {
				continue;
			}
			$condition = null === $node->typeCondition ? $type : $info->schema->getType( $node->typeCondition->name->value );
			if ( $condition !== $type && ! ( $condition instanceof AbstractType && $info->schema->isSubType( $condition, $type ) ) ) {
				continue;
			}
			foreach ( $this->collect( $node->selectionSet, $type, $info, $visited ) as $key => $nodes ) {
				$fields[ $key ] = array_merge( $fields[ $key ] ?? [], $nodes );
			}
		}
		return $fields;
	}

	private function selects_customer_token( ResolveInfo $info ) {
		$type = 'mutation' === $info->operation->operation ? $info->schema->getMutationType() : $info->schema->getQueryType();
		return $this->has_customer_token( $info->operation->selectionSet, $type, $info );
	}

	private function has_customer_token( $selection, ObjectType $type, ResolveInfo $info ) {
		foreach ( $this->collect( $selection, $type, $info ) as $nodes ) {
			foreach ( $nodes as $node ) {
				$name = $node->name->value;
				if ( ( 'Customer' === $type->name && 'sessionToken' === $name )
					|| ( 'User' === $type->name && 'wooSessionToken' === $name ) ) {
					return true;
				}
				if ( '__typename' === $name || null === $node->selectionSet ) {
					continue;
				}
				$child = Type::getNamedType( $type->getField( $name )->getType() );
				$types = $child instanceof AbstractType ? $info->schema->getPossibleTypes( $child ) : [ $child ];
				foreach ( $types as $possible ) {
					if ( $possible instanceof ObjectType && $this->has_customer_token( $node->selectionSet, $possible, $info ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}
}
