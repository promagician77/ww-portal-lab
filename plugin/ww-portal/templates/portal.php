<?php
/**
 * App-style page shell for portal pages (pages with meta _wwp_app = 1).
 * In production this would sit inside the site's Elementor header/footer instead.
 */
defined( 'ABSPATH' ) || exit;
$u = wp_get_current_user();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'wwp-app' ); ?>>
<header class="wwp-top">
	<a class="wwp-brand" href="<?php echo esc_url( home_url( '/portal/' ) ); ?>"><span class="wwp-mark" aria-hidden="true"></span>Coaching Portal <small>demo</small></a>
	<nav aria-label="Main">
		<?php
		$links = array( '/portal/' => 'Dashboard', '/my-numbers/' => 'My numbers', '/money-reset/week-2/' => 'Course' );
		if ( current_user_can( 'wwp_coach' ) ) {
			$links['/coach/'] = 'Coach';
		}
		$here = trailingslashit( wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) );
		foreach ( $links as $path => $label ) {
			printf( '<a href="%s"%s>%s</a>', esc_url( home_url( $path ) ), $here === $path ? ' aria-current="page"' : '', esc_html( $label ) );
		}
		?>
	</nav>
	<?php if ( $u->exists() ) : ?>
		<span class="wwp-who"><?php echo esc_html( $u->display_name ); ?> · <a href="<?php echo esc_url( wp_logout_url( home_url( '/portal/' ) ) ); ?>">Log out</a></span>
	<?php endif; ?>
</header>
<main class="wwp-main">
	<?php
	while ( have_posts() ) {
		the_post();
		if ( get_post_meta( get_the_ID(), '_wwp_show_title', true ) ) {
			echo '<div class="wwp-pagehead"><p class="wwp-eyebrow">' . esc_html( get_post_meta( get_the_ID(), '_wwp_eyebrow', true ) ) . '</p><h1>' . esc_html( get_the_title() ) . '</h1></div>';
		}
		the_content();
	}
	?>
</main>
<?php wp_footer(); ?>
</body>
</html>
