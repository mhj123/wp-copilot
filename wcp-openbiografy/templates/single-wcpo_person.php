<?php
/**
 * Public person page: bio header → narrative chapters (published only, with
 * source footnotes) → life timeline (accepted events; accepted-but-not-yet-
 * consolidated dated facts render lighter) → sources list.
 *
 * Themes can override by providing their own single-wcpo_person.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

while (have_posts()) :
    the_post();
    $person_id = get_the_ID();
    $person    = WCPO_Person_Repo::meta($person_id);
    $chapters  = WCPO_Chapter_Repo::list_for_person($person_id, true);
    $events    = WCPO_Event_Repo::accepted($person_id);
    $loose     = WCPO_Fact_Repo::accepted_dated($person_id);
    $map       = WCPO_Frontend::source_map($person_id);

    $life_bits = array_filter(array(
        WCPO_EDTF::format($person['birth_edtf']),
        WCPO_EDTF::format($person['death_edtf']),
    ));
    ?>
    <article class="wcpo-person">
        <header class="wcpo-hero">
            <?php if (has_post_thumbnail()) : ?>
                <div class="wcpo-portrait"><?php the_post_thumbnail('medium'); ?></div>
            <?php endif; ?>
            <div class="wcpo-hero-text">
                <h1><?php the_title(); ?></h1>
                <?php if ($life_bits) : ?>
                    <p class="wcpo-lifespan"><?php echo esc_html(implode(' – ', $life_bits)); ?></p>
                <?php endif; ?>
                <?php if ($person['occupation']) : ?>
                    <p class="wcpo-occupation"><?php echo esc_html($person['occupation']); ?></p>
                <?php endif; ?>
                <?php if (get_the_content()) : ?>
                    <div class="wcpo-bio"><?php the_content(); ?></div>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($chapters) : ?>
            <section class="wcpo-chapters">
                <h2><?php _e('Chapters', 'wcp-openbiografy'); ?></h2>
                <?php foreach ($chapters as $chapter_post) :
                    $c = WCPO_Chapter_Repo::meta($chapter_post->ID);
                    if (trim($c['narrative']) === '') {
                        continue;
                    }
                    ?>
                    <section class="wcpo-chapter" id="wcpo-chapter-<?php echo (int) $c['id']; ?>">
                        <h3><?php echo esc_html($c['title']); ?></h3>
                        <?php if ($c['period_display']) : ?>
                            <p class="wcpo-period"><?php echo esc_html($c['period_display']); ?></p>
                        <?php endif; ?>
                        <div class="wcpo-narrative">
                            <?php echo wp_kses_post(WCPO_Frontend::render_narrative($c['narrative'], $map)); ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if ($events || $loose) : ?>
            <section class="wcpo-timeline">
                <h2><?php _e('Life timeline', 'wcp-openbiografy'); ?></h2>
                <ol class="wcpo-timeline-list">
                    <?php
                    // Merge accepted events with accepted-but-unconsolidated
                    // facts (rendered lighter) into one chronological stream.
                    $items = array();
                    foreach ($events as $event_post) {
                        $e = WCPO_Event_Repo::meta($event_post->ID);
                        $items[] = array('type' => 'event', 'sort' => $e['sort_start'], 'data' => $e);
                    }
                    foreach ($loose as $fact_post) {
                        $f = WCPO_Fact_Repo::meta($fact_post->ID);
                        $items[] = array('type' => 'fact', 'sort' => $f['sort_start'], 'data' => $f);
                    }
                    usort($items, function ($a, $b) {
                        if ($a['sort'] === $b['sort']) {
                            return $a['data']['id'] <=> $b['data']['id'];
                        }
                        if ($a['sort'] === '') {
                            return 1; // undated last
                        }
                        if ($b['sort'] === '') {
                            return -1;
                        }
                        return strcmp($a['sort'], $b['sort']);
                    });

                    $last_year = null;
                    foreach ($items as $item) :
                        $d = $item['data'];
                        $year = WCPO_EDTF::year($d['date_edtf']);
                        $year_label = '';
                        if ($year && $year !== $last_year) {
                            $year_label = (string) $year;
                            $last_year = $year;
                        } elseif (!$year) {
                            $year_label = _x('·', 'undated timeline marker', 'wcp-openbiografy');
                        }

                        if ($item['type'] === 'event') :
                            $e = $d;
                            ?>
                            <li class="wcpo-timeline-item <?php echo $e['contested'] ? 'wcpo-contested' : ''; ?>">
                                <span class="wcpo-tl-year"><?php echo esc_html($year_label); ?></span>
                                <div class="wcpo-tl-body">
                                    <strong><?php echo esc_html($e['title']); ?></strong>
                                    <?php echo WCPO_Frontend::footnote_sup($e['id'], $map); // phpcs:ignore ?>
                                    <span class="wcpo-tl-meta">
                                        <?php echo esc_html($e['date_display'] ?: __('undated', 'wcp-openbiografy')); ?>
                                        <?php echo $e['place'] ? ' · ' . esc_html($e['place']) : ''; ?>
                                    </span>
                                    <?php if ($e['description']) : ?>
                                        <p><?php echo esc_html($e['description']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($e['contested']) : ?>
                                        <p class="wcpo-contested-note">⚖ <?php echo esc_html($e['contested_note'] ?: __('Sources disagree about this event.', 'wcp-openbiografy')); ?></p>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php else :
                            $f = $d;
                            $fn = isset($map[$f['source_id']]) ? '<sup class="wcpo-fn">[<a href="#wcpo-fn-' . (int) $map[$f['source_id']] . '">' . (int) $map[$f['source_id']] . '</a>]</sup>' : '';
                            ?>
                            <li class="wcpo-timeline-item wcpo-loose-fact">
                                <span class="wcpo-tl-year"><?php echo esc_html($year_label); ?></span>
                                <div class="wcpo-tl-body">
                                    <?php echo esc_html($f['claim']); ?><?php echo $fn; // phpcs:ignore ?>
                                    <span class="wcpo-tl-meta"><?php echo esc_html($f['date_display']); ?></span>
                                </div>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endif; ?>

        <?php $footnotes = WCPO_Frontend::footnotes($map); ?>
        <?php if ($footnotes) : ?>
            <section class="wcpo-sources">
                <h2><?php _e('Sources', 'wcp-openbiografy'); ?></h2>
                <ol class="wcpo-footnotes">
                    <?php foreach ($footnotes as $n => $source) :
                        $link = $source['url'] ?: $source['attachment_url'];
                        $cite = WCPO_Source_Repo::citation_line($source['id']);
                        ?>
                        <li id="wcpo-fn-<?php echo (int) $n; ?>">
                            <?php if ($link) : ?>
                                <a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener"><?php echo esc_html($cite ?: $source['title']); ?></a>
                            <?php else : ?>
                                <?php echo esc_html($cite ?: $source['title']); ?>
                            <?php endif; ?>
                            <?php if ($source['source_tier'] && $source['source_tier'] !== 'unknown') : ?>
                                <span class="wcpo-tier"><?php echo esc_html(str_replace('_', ' ', $source['source_tier'])); ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endif; ?>
    </article>
    <?php
endwhile;

get_footer();
