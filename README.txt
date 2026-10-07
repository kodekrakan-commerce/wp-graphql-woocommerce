=== WPGraphQL for WooCommerce ===
Contributors: kidunot89, ranaaterning, jasonbahl, saleebm
Tags: GraphQL, WooCommerce, WPGraphQL
Requires at least: 6.1
Tested up to: 6.2
Requires PHP: 8.2
Requires WooCommerce: 8.9.0
Requires WPGraphQL: 1.25.0+
Works with WPGraphQL-JWT-Authentication: 0.7.0+
Stable tag: 0.21.2
License: GPL-3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Maintained at: https://github.com/wp-graphql/wp-graphql-woocommerce

== Description ==
Adds WooCommerce functionality to the WPGraphQL schema.

== Usage ==
1. Install & activate [WooCommerce](https://woocommerce.com/)
2. Install & activate [WPGraphQL](https://www.wpgraphql.com/)
3. Install the retained fork's reviewed distribution artifact, which includes `vendor/` and `vendor-prefixed/`, then activate the **WP GraphQL WooCommerce** plugin. Composer-managed applications may install this package from their WordPress/application root using their reviewed lockfile. Do not run `composer install`, `composer update`, or `composer dump-autoload` inside this prebuilt plugin directory.
4. (Optional) Install & activate [WPGraphQL-JWT-Authentication](https://github.com/wp-graphql/wp-graphql-jwt-authentication) to add a `login` mutation that returns a JSON Web Token.
5. (Optional) Install & activate [WPGraphQL-CORS](https://github.com/funkhaus/wp-graphql-cors) to add an extra layer of security using HTTP CORS and some of WPGraphQL advanced functionality.

== Retained Distribution and Qualification ==
This retained fork is based on the upstream v0.21.2 distribution. It ships bundled Firebase PHP-JWT 7.1.0, its prefixed classes, and the reviewed generated vendor autoload files. Its Composer package identity is `wp-graphql/wp-graphql-woocommerce`, with type `wordpress-plugin`. The root package requires PHP >=8.2; the bundled vendor platform check reflects the JWT dependency's PHP >=8.0 floor and does not establish the retained package's supported PHP floor.

The assembled synthetic guest-cart additive-overlap qualification used PHP 8.2.34, WordPress 7.1.2, WPGraphQL 2.23.1, WooCommerce 10.6.0, WPGraphQL Headless Login 0.4.4, MySQL 8.0.46, and mysqli/mysqlnd. This is evidence for the exercised synthetic cart-session, lifecycle, storage, and database-driver paths in that exact source/runtime cohort. It does not establish production-store acceptance, full checkout/payment compatibility, or support for older or future dependency versions.

GraphQL cart-session handling additionally requires Owned_Scope_Driver capability version 1 through a separately reviewed early WordPress `wp-content/db.php` installation. Installing the MU package with Composer does not activate that drop-in. The actual core/plugin sources, callback registry, database, and runtime must satisfy the reviewed cohort; installing this plugin alone does not qualify a store.

The existing non-PHP WordPress, WooCommerce, WPGraphQL, and authentication dependency/tested-up-to fields are preserved historical upstream metadata. In particular, WooCommerce 8.9.0/9.3.3, WPGraphQL 1.25.0+/1.27.0+, and WordPress tested-up-to 6.2 are unqualified for the new cart-session path. They are not newly accepted compatibility evidence; use the exact synthetic qualification above and qualify each intended installation separately.
