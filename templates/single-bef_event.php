<?php
/**
 * Single template for BEF events.
 *
 * @package BEF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

wp_enqueue_style( 'bef-calendar-frontend' );

get_header();

while ( have_posts() ) :
    the_post();

    $event_id          = get_the_ID();
    $event_date        = get_post_meta( $event_id, BEF_Calendar::META_DATE, true );
    $event_end         = get_post_meta( $event_id, BEF_Calendar::META_END_DATE, true );
    $start_time        = get_post_meta( $event_id, BEF_Calendar::META_START_TIME, true );
    $end_time          = get_post_meta( $event_id, BEF_Calendar::META_END_TIME, true );
    $location          = get_post_meta( $event_id, BEF_Calendar::META_LOCATION, true );
    $external_url      = get_post_meta( $event_id, BEF_Calendar::META_URL, true );
    $ticket_url        = get_post_meta( $event_id, BEF_Calendar::META_TICKET_URL, true );
    $ticket_label      = get_post_meta( $event_id, BEF_Calendar::META_TICKET_LABEL, true );
    $archive_url       = get_post_type_archive_link( BEF_Calendar::POST_TYPE );
    $event_terms       = get_the_terms( $event_id, BEF_Calendar::TAXONOMY );
    $remote_image      = get_post_meta( $event_id, BEF_Calendar::META_REMOTE_IMAGE_URL, true );
    $eventbrite_summary = get_post_meta( $event_id, BEF_Calendar::META_EVENTBRITE_SUMMARY, true );
    $eventbrite_organizer = get_post_meta( $event_id, BEF_Calendar::META_EVENTBRITE_ORGANIZER, true );
    $eventbrite_venue_address = get_post_meta( $event_id, BEF_Calendar::META_EVENTBRITE_VENUE_ADDRESS, true );
    $source_meta       = get_post_meta( $event_id, BEF_Calendar::META_SOURCE, true );
    $recurrence_summary = bef_calendar() ? bef_calendar()->get_event_recurrence_summary( $event_id ) : '';
    $next_occurrences   = bef_calendar() ? bef_calendar()->get_event_next_occurrences( $event_id, 6 ) : array();

    if ( ! $ticket_label ) {
        $ticket_label = __( 'Purchase Tickets', 'bef-calendar' );
    }

    $date_text = '';
    if ( $event_date ) {
        $date_text = wp_date( get_option( 'date_format' ), strtotime( $event_date ) );
        if ( $event_end && $event_end !== $event_date ) {
            $date_text .= ' - ' . wp_date( get_option( 'date_format' ), strtotime( $event_end ) );
        }
    }

    $time_text = '';
    if ( $start_time ) {
        $time_text = date_i18n( get_option( 'time_format' ), strtotime( $start_time ) );
        if ( $end_time ) {
            $time_text .= ' - ' . date_i18n( get_option( 'time_format' ), strtotime( $end_time ) );
        }
    } elseif ( $end_time ) {
        $time_text = date_i18n( get_option( 'time_format' ), strtotime( $end_time ) );
    }

    $excerpt             = has_excerpt() ? get_the_excerpt() : '';
    $google_calendar_url = bef_calendar() ? bef_calendar()->get_google_calendar_url( $event_id ) : '';
    $ics_export_url      = bef_calendar() ? bef_calendar()->get_ics_export_url( $event_id ) : '';
    $display_image       = bef_calendar() ? bef_calendar()->get_event_image_url( $event_id, 'large' ) : ( $remote_image ? $remote_image : trailingslashit( BEF_CALENDAR_URL ) . 'assets/images/default-event-image.svg' );
    ?>
    <main id="primary" class="bef-event-single">
        <article <?php post_class( 'bef-event-single__article' ); ?>>
            <div class="bef-event-single__shell">
                <header class="bef-event-single__header">
                    <div class="bef-event-single__copy">
                        <p class="bef-calendar-kicker"><?php esc_html_e( 'British Esports Calendar Event', 'bef-calendar' ); ?></p>

                        <?php if ( ! empty( $event_terms ) && ! is_wp_error( $event_terms ) ) : ?>
                            <div class="bef-event-single__terms">
                                <?php foreach ( $event_terms as $term ) : ?>
                                    <a class="bef-archive-chip" href="<?php echo esc_url( add_query_arg( 'event_category', $term->slug, $archive_url ) ); ?>"><?php echo esc_html( $term->name ); ?></a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <h1 class="bef-event-single__title"><?php the_title(); ?></h1>

                        <?php if ( $excerpt ) : ?>
                            <div class="bef-event-single__excerpt"><?php echo wp_kses_post( wpautop( $excerpt ) ); ?></div>
                        <?php endif; ?>

                        <div class="bef-event-single__meta">
                            <?php if ( $date_text ) : ?>
                                <div class="bef-event-single__meta-item">
                                    <span class="bef-event-single__meta-label"><?php echo esc_html( $recurrence_summary ? __( 'Starts', 'bef-calendar' ) : __( 'Date', 'bef-calendar' ) ); ?></span>
                                    <strong><?php echo esc_html( $date_text ); ?></strong>
                                </div>
                            <?php endif; ?>

                            <?php if ( $time_text ) : ?>
                                <div class="bef-event-single__meta-item">
                                    <span class="bef-event-single__meta-label"><?php esc_html_e( 'Time', 'bef-calendar' ); ?></span>
                                    <strong><?php echo esc_html( $time_text ); ?></strong>
                                </div>
                            <?php endif; ?>

                            <?php if ( $location ) : ?>
                                <div class="bef-event-single__meta-item">
                                    <span class="bef-event-single__meta-label"><?php esc_html_e( 'Location', 'bef-calendar' ); ?></span>
                                    <strong><?php echo esc_html( $location ); ?></strong>
                                </div>
                            <?php endif; ?>

                            <?php if ( 'eventbrite' === $source_meta && $eventbrite_organizer ) : ?>
                                <div class="bef-event-single__meta-item">
                                    <span class="bef-event-single__meta-label"><?php esc_html_e( 'Organiser', 'bef-calendar' ); ?></span>
                                    <strong><?php echo esc_html( $eventbrite_organizer ); ?></strong>
                                </div>
                            <?php endif; ?>

                            <?php if ( 'eventbrite' === $source_meta && $eventbrite_venue_address ) : ?>
                                <div class="bef-event-single__meta-item bef-event-single__meta-item--wide">
                                    <span class="bef-event-single__meta-label"><?php esc_html_e( 'Venue Details', 'bef-calendar' ); ?></span>
                                    <strong><?php echo esc_html( $eventbrite_venue_address ); ?></strong>
                                </div>
                            <?php endif; ?>

                            <?php if ( $recurrence_summary ) : ?>
                                <div class="bef-event-single__meta-item bef-event-single__meta-item--wide">
                                    <span class="bef-event-single__meta-label"><?php esc_html_e( 'Repeats', 'bef-calendar' ); ?></span>
                                    <strong><?php echo esc_html( $recurrence_summary ); ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ( $recurrence_summary && ! empty( $next_occurrences ) ) : ?>
                            <div class="bef-event-single__occurrences">
                                <p class="bef-event-single__calendar-label"><?php esc_html_e( 'Upcoming dates', 'bef-calendar' ); ?></p>
                                <ul class="bef-event-single__occurrence-list">
                                    <?php foreach ( $next_occurrences as $occurrence ) : ?>
                                        <?php
                                        $occurrence_text = wp_date( get_option( 'date_format' ), strtotime( $occurrence['date'] ) );
                                        if ( ! empty( $occurrence['end_date'] ) && $occurrence['end_date'] !== $occurrence['date'] ) {
                                            $occurrence_text .= ' - ' . wp_date( get_option( 'date_format' ), strtotime( $occurrence['end_date'] ) );
                                        }
                                        ?>
                                        <li><?php echo esc_html( $occurrence_text ); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <div class="bef-event-single__actions">
                            <?php if ( $archive_url ) : ?>
                                <a class="bef-calendar-button bef-calendar-button--ghost" href="<?php echo esc_url( $archive_url ); ?>">
                                    <?php esc_html_e( 'Back to Events', 'bef-calendar' ); ?>
                                </a>
                            <?php endif; ?>

                            <?php if ( $ticket_url ) : ?>
                                <a class="bef-calendar-button" href="<?php echo esc_url( $ticket_url ); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php echo esc_html( $ticket_label ); ?>
                                </a>
                            <?php endif; ?>

                            <?php if ( $external_url ) : ?>
                                <a class="bef-calendar-button bef-calendar-button--ghost" href="<?php echo esc_url( $external_url ); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php esc_html_e( 'Visit Event Website', 'bef-calendar' ); ?>
                                </a>
                            <?php endif; ?>
                        </div>

                        <?php if ( $google_calendar_url || $ics_export_url ) : ?>
                            <div class="bef-event-single__calendar-actions">
                                <p class="bef-event-single__calendar-label"><?php esc_html_e( 'Add this event to your calendar', 'bef-calendar' ); ?></p>
                                <div class="bef-event-single__calendar-buttons">
                                    <?php if ( $google_calendar_url ) : ?>
                                        <a class="bef-calendar-button bef-calendar-button--ghost" href="<?php echo esc_url( $google_calendar_url ); ?>" target="_blank" rel="noopener noreferrer">
                                            <?php esc_html_e( 'Google Calendar', 'bef-calendar' ); ?>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ( $ics_export_url ) : ?>
                                        <a class="bef-calendar-button bef-calendar-button--ghost" href="<?php echo esc_url( $ics_export_url ); ?>">
                                            <?php esc_html_e( 'Apple Calendar', 'bef-calendar' ); ?>
                                        </a>
                                        <a class="bef-calendar-button bef-calendar-button--ghost" href="<?php echo esc_url( $ics_export_url ); ?>">
                                            <?php esc_html_e( 'Download ICS', 'bef-calendar' ); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ( $display_image ) : ?>
                        <div class="bef-event-single__media">
                            <img class="bef-event-single__image" src="<?php echo esc_url( $display_image ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>">
                        </div>
                    <?php endif; ?>
                </header>

                <div class="bef-event-single__content">
                    <?php if ( 'eventbrite' === $source_meta && $eventbrite_summary ) : ?>
                        <div class="bef-event-single__eventbrite-summary">
                            <h2><?php esc_html_e( 'About this Eventbrite event', 'bef-calendar' ); ?></h2>
                            <?php echo wp_kses_post( wpautop( $eventbrite_summary ) ); ?>
                        </div>
                    <?php endif; ?>

                    <?php the_content(); ?>
                </div>
            </div>
        </article>
    </main>
    <?php
endwhile;

get_footer();
