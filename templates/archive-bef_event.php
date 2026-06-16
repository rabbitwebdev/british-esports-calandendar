<?php
/**
 * Archive template for BEF events.
 *
 * @package BEF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

wp_enqueue_style( 'bef-calendar-frontend' );

get_header();

$archive_title       = post_type_archive_title( '', false );
$archive_description = get_the_archive_description();
$archive_url         = get_post_type_archive_link( BEF_Calendar::POST_TYPE );
$view                = isset( $_GET['event_view'] ) ? sanitize_key( wp_unslash( $_GET['event_view'] ) ) : 'upcoming';
$allowed_views       = array( 'upcoming', 'past', 'all' );
$view                = in_array( $view, $allowed_views, true ) ? $view : 'upcoming';
$selected_category   = isset( $_GET['event_category'] ) ? sanitize_title( wp_unslash( $_GET['event_category'] ) ) : '';
$paged               = max( 1, get_query_var( 'paged' ), get_query_var( 'page' ) );
$posts_per_page      = 12;
$taxonomy            = BEF_Calendar::TAXONOMY;
$category_terms      = get_terms(
    array(
        'taxonomy'   => $taxonomy,
        'hide_empty' => true,
    )
);
$selected_term       = null;

if ( $selected_category ) {
    $selected_term = get_term_by( 'slug', $selected_category, $taxonomy );

    if ( ! $selected_term || is_wp_error( $selected_term ) ) {
        $selected_category = '';
        $selected_term     = null;
    }
}

$archive_results = bef_calendar() ? bef_calendar()->get_archive_occurrence_results( $view, $selected_category, $paged, $posts_per_page ) : array(
    'items'       => array(),
    'total'       => 0,
    'total_pages' => 1,
);
$archive_items   = ! empty( $archive_results['items'] ) ? $archive_results['items'] : array();
$total_items     = ! empty( $archive_results['total'] ) ? (int) $archive_results['total'] : 0;
$total_pages     = ! empty( $archive_results['total_pages'] ) ? (int) $archive_results['total_pages'] : 1;

$build_archive_url = static function ( $target_view = 'upcoming', $target_category = '' ) use ( $archive_url ) {
    if ( ! $archive_url ) {
        return '#';
    }

    $args = array();

    if ( 'upcoming' !== $target_view ) {
        $args['event_view'] = $target_view;
    }

    if ( $target_category ) {
        $args['event_category'] = $target_category;
    }

    $url = remove_query_arg( array( 'event_view', 'event_category', 'paged' ), $archive_url );

    return ! empty( $args ) ? add_query_arg( $args, $url ) : $url;
};

$view_labels = array(
    'upcoming' => __( 'Upcoming Events', 'bef-calendar' ),
    'past'     => __( 'Past Events', 'bef-calendar' ),
    'all'      => __( 'All Events', 'bef-calendar' ),
);
?>
<main id="primary" class="bef-event-archive">
    <section class="bef-event-archive__hero">
        <div class="bef-event-archive__hero-shell">
            <p class="bef-calendar-kicker"><?php esc_html_e( 'British Esports Event Archive', 'bef-calendar' ); ?></p>
            <h1 class="bef-event-archive__title"><?php echo esc_html( $archive_title ? $archive_title : __( 'Events', 'bef-calendar' ) ); ?></h1>

            <div class="bef-event-archive__intro">
                <?php if ( $archive_description ) : ?>
                    <?php echo wp_kses_post( wpautop( $archive_description ) ); ?>
                <?php else : ?>
                    <p><?php esc_html_e( 'Browse published British Esports calendar events, catch what is coming up next, and dip back into the archive whenever you need the highlights reel.', 'bef-calendar' ); ?></p>
                <?php endif; ?>
            </div>

            <div class="bef-event-archive__filter-stack">
                <div>
                    <p class="bef-event-archive__filter-label"><?php esc_html_e( 'Browse by date', 'bef-calendar' ); ?></p>
                    <div class="bef-event-archive__filters" aria-label="<?php esc_attr_e( 'Event archive views', 'bef-calendar' ); ?>">
                        <?php foreach ( $view_labels as $view_key => $view_label ) : ?>
                            <a class="bef-archive-pill<?php echo $view === $view_key ? ' is-active' : ''; ?>" href="<?php echo esc_url( $build_archive_url( $view_key, $selected_category ) ); ?>">
                                <?php echo esc_html( $view_label ); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ( ! is_wp_error( $category_terms ) && ! empty( $category_terms ) ) : ?>
                    <div>
                        <p class="bef-event-archive__filter-label"><?php esc_html_e( 'Browse by category', 'bef-calendar' ); ?></p>
                        <div class="bef-event-archive__filters" aria-label="<?php esc_attr_e( 'Event categories', 'bef-calendar' ); ?>">
                            <a class="bef-archive-pill<?php echo '' === $selected_category ? ' is-active' : ''; ?>" href="<?php echo esc_url( $build_archive_url( $view ) ); ?>">
                                <?php esc_html_e( 'All Categories', 'bef-calendar' ); ?>
                            </a>

                            <?php foreach ( $category_terms as $term ) : ?>
                                <a class="bef-archive-pill<?php echo $selected_category === $term->slug ? ' is-active' : ''; ?>" href="<?php echo esc_url( $build_archive_url( $view, $term->slug ) ); ?>">
                                    <?php echo esc_html( $term->name ); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="bef-event-archive__listing">
        <div class="bef-event-archive__header-row">
            <div>
                <p class="bef-calendar-mini-label"><?php esc_html_e( 'Currently viewing', 'bef-calendar' ); ?></p>
                <h2 class="bef-event-archive__section-title"><?php echo esc_html( $view_labels[ $view ] ); ?></h2>

                <?php if ( $selected_term ) : ?>
                    <p class="bef-event-archive__selected-summary">
                        <?php
                        printf(
                            /* translators: %s: category name. */
                            esc_html__( 'Filtered to category: %s', 'bef-calendar' ),
                            esc_html( $selected_term->name )
                        );
                        ?>
                    </p>
                <?php endif; ?>
            </div>

            <?php if ( $total_items ) : ?>
                <p class="bef-event-archive__count">
                    <?php
                    printf(
                        /* translators: %s: number of events. */
                        esc_html( _n( '%s event', '%s events', $total_items, 'bef-calendar' ) ),
                        esc_html( number_format_i18n( $total_items ) )
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>

        <?php if ( ! empty( $archive_items ) ) : ?>
            <div class="bef-event-archive__grid">
                <?php foreach ( $archive_items as $item ) : ?>
                    <?php
                    $date_display = '';
                    if ( ! empty( $item['date'] ) ) {
                        $date_display = wp_date( get_option( 'date_format' ), strtotime( $item['date'] ) );

                        if ( ! empty( $item['end_date'] ) && $item['end_date'] !== $item['date'] ) {
                            $date_display .= ' - ' . wp_date( get_option( 'date_format' ), strtotime( $item['end_date'] ) );
                        }
                    }

                    $time_display = '';
                    if ( ! empty( $item['start_time'] ) ) {
                        $time_display = date_i18n( get_option( 'time_format' ), strtotime( $item['start_time'] ) );

                        if ( ! empty( $item['end_time'] ) ) {
                            $time_display .= ' - ' . date_i18n( get_option( 'time_format' ), strtotime( $item['end_time'] ) );
                        }
                    } elseif ( ! empty( $item['end_time'] ) ) {
                        $time_display = date_i18n( get_option( 'time_format' ), strtotime( $item['end_time'] ) );
                    }
                    ?>
                    <article class="bef-archive-card">
                        <a class="bef-archive-card__overlay" href="<?php echo esc_url( $item['permalink'] ); ?>" aria-label="<?php echo esc_attr( $item['title'] ); ?>"></a>

                        <?php if ( ! empty( $item['thumbnail'] ) || ! empty( $item['remote_image'] ) ) : ?>
                            <div class="bef-archive-card__media">
                                <img class="bef-archive-card__image" src="<?php echo esc_url( ! empty( $item['thumbnail'] ) ? $item['thumbnail'] : $item['remote_image'] ); ?>" alt="<?php echo esc_attr( $item['title'] ); ?>">
                            </div>
                        <?php endif; ?>

                        <div class="bef-archive-card__body">
                        <div class="bef-archive-card__terms">
                            <?php if ( ! empty( $item['terms'] ) && ! is_wp_error( $item['terms'] ) ) : ?>
                              
                                    <?php foreach ( $item['terms'] as $term ) : ?>
                                        <a class="bef-archive-chip" href="<?php echo esc_url( $build_archive_url( $view, $term->slug ) ); ?>"><?php echo esc_html( $term->name ); ?></a>
                                    <?php endforeach; ?>
                             
                            <?php endif; ?>

                            <?php if ( $date_display ) : ?>
                                <p class="bef-archive-card__eyebrow"><?php echo esc_html( $date_display ); ?></p>
                            <?php endif; ?>
                            </div>
                            <?php if ( ! empty( $item['source_label'] ) ) : ?>
                                <p class="bef-event-source"><?php echo esc_html( $item['source_label'] ); ?></p>
                            <?php endif; ?>

                            <h3 class="bef-archive-card__title"><?php echo esc_html( $item['title'] ); ?></h3>

                            <div class="bef-archive-card__meta">
                                <?php if ( $time_display ) : ?>
                                    <span><?php echo esc_html( $time_display ); ?></span>
                                <?php endif; ?>

                                <?php if ( ! empty( $item['location'] ) ) : ?>
                                    <span><?php echo esc_html( $item['location'] ); ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if ( ! empty( $item['recurrence_summary'] ) ) : ?>
                                <p class="bef-event-recurrence"><?php echo esc_html( $item['recurrence_summary'] ); ?></p>
                            <?php endif; ?>

                            <?php if ( ! empty( $item['excerpt'] ) ) : ?>
                                <div class="bef-archive-card__excerpt"><?php echo wp_kses_post( wpautop( $item['excerpt'] ) ); ?></div>
                            <?php endif; ?>

                            <div class="bef-archive-card__actions">
                                <a class="bef-event-link bef-event-link--detail" href="<?php echo esc_url( $item['permalink'] ); ?>">
                                    <?php esc_html_e( 'View Event', 'bef-calendar' ); ?>
                                </a>

                                <?php if ( ! empty( $item['ticket_url'] ) ) : ?>
                                    <a class="bef-event-link bef-event-link--ticket" href="<?php echo esc_url( $item['ticket_url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo esc_html( $item['ticket_label'] ); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php
            $pagination_args = array();

            if ( 'upcoming' !== $view ) {
                $pagination_args['event_view'] = $view;
            }

            if ( $selected_category ) {
                $pagination_args['event_category'] = $selected_category;
            }

            $pagination = paginate_links(
                array(
                    'base'      => trailingslashit( $archive_url ) . '%_%',
                    'format'    => 'page/%#%/',
                    'current'   => $paged,
                    'total'     => max( 1, $total_pages ),
                    'add_args'  => $pagination_args,
                    'type'      => 'list',
                    'prev_text' => __( 'Previous', 'bef-calendar' ),
                    'next_text' => __( 'Next', 'bef-calendar' ),
                )
            );

            if ( $pagination ) :
                ?>
                <nav class="bef-event-archive__pagination" aria-label="<?php esc_attr_e( 'Event archive pagination', 'bef-calendar' ); ?>">
                    <?php echo wp_kses_post( $pagination ); ?>
                </nav>
                <?php
            endif;
            ?>
        <?php else : ?>
            <div class="bef-event-archive__empty">
                <h2><?php esc_html_e( 'No events matched this view just yet.', 'bef-calendar' ); ?></h2>
                <p><?php esc_html_e( 'Try a different date view, remove the category filter, or add your next event to get the archive moving again.', 'bef-calendar' ); ?></p>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php
get_footer();
