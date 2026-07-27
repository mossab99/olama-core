<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The only administration surface that mutates academic years, semesters,
 * or the active academic context.
 */
class Olama_Core_Academic_Admin {
    private $core;

    public function __construct(Olama_Core_Container $core) {
        $this->core = $core;
    }

    public function init() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_post_olama_core_save_academic_year', array($this, 'save_year'));
        add_action('admin_post_olama_core_delete_academic_year', array($this, 'delete_year'));
        add_action('admin_post_olama_core_save_semester', array($this, 'save_semester'));
        add_action('admin_post_olama_core_delete_semester', array($this, 'delete_semester'));
        add_action('admin_post_olama_core_set_academic_context', array($this, 'set_context'));
        add_action('admin_post_olama_core_save_year_source_mapping', array($this, 'save_year_source_mapping'));
        add_action('admin_post_olama_core_create_year_archive', array($this, 'create_year_archive'));
        add_action('admin_post_olama_core_verify_year_archive', array($this, 'verify_year_archive'));
        add_action('admin_post_olama_core_purge_year_archive', array($this, 'purge_year_archive'));
        add_action('admin_post_olama_core_restore_year_archive', array($this, 'restore_year_archive'));
    }

    public function register_menu() {
        add_submenu_page(
            'olama-core',
            __('Academic Operations', 'olama-core'),
            __('الإدارة الأكاديمية', 'olama-core'),
            'manage_olama_academic_context',
            'olama-core-academic-calendar',
            array($this, 'render'),
            4
        );
    }

    public function render() {
        $this->authorize();
        $calendar = $this->core->academic_calendar();
        $years = $calendar->years();
        $current = $this->core->academic_context()->current();
        $selected_year_id = absint($_GET['year_id'] ?? ($current ? $current->academic_year_id : ($years[0]->id ?? 0)));
        $selected_year = $selected_year_id ? $calendar->year($selected_year_id) : null;
        $semesters = $selected_year ? $calendar->semesters($selected_year_id) : array();
        $edit_year = !empty($_GET['edit_year']) ? $calendar->year(absint($_GET['edit_year'])) : null;
        $edit_semester = !empty($_GET['edit_semester']) ? $calendar->semester(absint($_GET['edit_semester'])) : null;

        echo '<div class="wrap olama-core-admin olama-academic-operations" dir="rtl"><div class="olama-page">';
        echo '<header class="olama-page-header"><div><h1 class="olama-page-title">' . esc_html__('الإدارة الأكاديمية', 'olama-core') . '</h1>';
        echo '<p class="olama-page-subtitle">' . esc_html__('إدارة السياق التشغيلي والسنوات والفصول وربط رموز الأنظمة الخارجية وإغلاق السنوات من مكان واحد.', 'olama-core') . '</p></div>';
        echo '<div class="olama-actions"><a class="olama-btn olama-btn-secondary" href="' . esc_url(admin_url('admin.php?page=olama-core-academic-info')) . '">' . esc_html__('استعراض البنية الأكاديمية', 'olama-core') . '</a></div></header>';
        $this->render_notice();

        echo '<div class="olama-context-banner"><span class="dashicons dashicons-calendar-alt"></span><div><span class="olama-label">' . esc_html__('السياق الأكاديمي الحالي', 'olama-core') . '</span><strong>';
        if ($current) {
            echo esc_html(sprintf('%s — %s', $current->study_year ?: $current->year_name, $current->semester_name));
        } else {
            echo esc_html__('غير مهيأ. اختر سنة وفصلاً دراسياً صالحين أدناه.', 'olama-core');
        }
        echo '</strong></div></div>';

        echo '<nav class="olama-tabs olama-anchor-tabs" aria-label="' . esc_attr__('أقسام الإدارة الأكاديمية', 'olama-core') . '">';
        echo '<a class="olama-tab" href="#academic-context">' . esc_html__('السياق الحالي', 'olama-core') . '</a>';
        echo '<a class="olama-tab" href="#academic-years">' . esc_html__('السنوات والربط', 'olama-core') . '</a>';
        echo '<a class="olama-tab" href="#academic-semesters">' . esc_html__('الفصول الدراسية', 'olama-core') . '</a>';
        echo '<a class="olama-tab is-danger" href="#academic-closeout">' . esc_html__('الإغلاق والأرشفة', 'olama-core') . '</a></nav>';

        echo '<section class="olama-section" id="academic-context">';
        $this->render_context_form($years, $current);
        echo '</section><section class="olama-section" id="academic-years">';
        $this->render_years($years, $current);
        $this->render_source_mappings($years);
        $this->render_year_form($edit_year);
        echo '</section><section class="olama-section" id="academic-semesters">';
        $this->render_semesters($selected_year, $semesters, $current);
        $this->render_semester_form($years, $edit_semester, $selected_year_id);
        echo '</section><section class="olama-section olama-danger-zone" id="academic-closeout">';
        $this->render_closeout($years, $current);
        echo '</section></div></div>';
    }

    private function render_context_form($years, $current) {
        echo '<hr><h2>' . esc_html__('السياق التشغيلي الحالي', 'olama-core') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('olama_core_set_academic_context');
        echo '<input type="hidden" name="action" value="olama_core_set_academic_context">';
        echo '<table class="form-table"><tr><th><label for="olama-core-context-year">' . esc_html__('السنة الأكاديمية', 'olama-core') . '</label></th><td>';
        echo '<select id="olama-core-context-year" name="academic_year_id" required><option value="">' . esc_html__('اختر السنة', 'olama-core') . '</option>';
        foreach ($years as $year) {
            printf('<option value="%d"%s>%s</option>', (int) $year->id, selected($current ? $current->academic_year_id : 0, $year->id, false), esc_html($year->code ?: $year->year_name));
        }
        echo '</select></td></tr><tr><th><label for="olama-core-context-semester">' . esc_html__('الفصل الدراسي', 'olama-core') . '</label></th><td>';
        echo '<select id="olama-core-context-semester" name="semester_id" required><option value="">' . esc_html__('اختر الفصل', 'olama-core') . '</option>';
        foreach ($years as $year) {
            foreach ($this->core->academic_calendar()->semesters($year->id) as $semester) {
                printf(
                    '<option value="%d" data-year="%d"%s>%s — %s</option>',
                    (int) $semester->id,
                    (int) $year->id,
                    selected($current ? $current->semester_id : 0, $semester->id, false),
                    esc_html($year->code ?: $year->year_name),
                    esc_html($semester->semester_name)
                );
            }
        }
        echo '</select><p class="description">' . esc_html__('يجب أن ينتمي الفصل إلى السنة المختارة. يتم تغيير القيمتين معاً.', 'olama-core') . '</p></td></tr></table>';
        submit_button(__('اعتماد السنة والفصل الحاليين', 'olama-core'));
        echo '</form>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){var y=document.getElementById("olama-core-context-year"),s=document.getElementById("olama-core-context-semester");function f(){Array.prototype.forEach.call(s.options,function(o){if(!o.value)return;o.hidden=o.dataset.year!==y.value;});if(s.selectedOptions.length&&s.selectedOptions[0].hidden)s.value="";}y.addEventListener("change",f);f();});</script>';
    }

    private function render_years($years, $current) {
        echo '<hr><h2>' . esc_html__('السنوات الأكاديمية', 'olama-core') . '</h2><table class="widefat striped"><thead><tr>';
        foreach (array('المعرّف', 'الرمز', 'الاسم', 'التواريخ', 'الحالة', 'الإجراءات') as $heading) {
            echo '<th>' . esc_html__($heading, 'olama-core') . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$years) {
            echo '<tr><td colspan="6">' . esc_html__('لا توجد سنوات أكاديمية معرفة.', 'olama-core') . '</td></tr>';
        }
        foreach ($years as $year) {
            $is_current = $current && (int) $current->academic_year_id === (int) $year->id;
            echo '<tr><td>' . (int) $year->id . '</td><td>' . esc_html($year->code ?: '—') . '</td><td>' . esc_html($year->year_name) . '</td>';
            echo '<td>' . esc_html($year->start_date . ' → ' . $year->end_date) . '</td><td>' . esc_html($is_current ? __('مفتوحة / حالية', 'olama-core') : ucfirst((string) $year->lifecycle_status)) . '</td><td>';
            echo '<a class="button button-small" href="' . esc_url(add_query_arg(array('page' => 'olama-core-academic-calendar', 'year_id' => $year->id, 'edit_year' => $year->id), admin_url('admin.php'))) . '">' . esc_html__('تعديل', 'olama-core') . '</a> ';
            echo '<a class="button button-small" href="' . esc_url(add_query_arg(array('page' => 'olama-core-academic-calendar', 'year_id' => $year->id), admin_url('admin.php'))) . '">' . esc_html__('الفصول', 'olama-core') . '</a>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function render_year_form($year) {
        echo '<h3>' . esc_html($year ? __('تعديل السنة الأكاديمية', 'olama-core') : __('إضافة سنة أكاديمية', 'olama-core')) . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_core_save_academic_year">';
        wp_nonce_field('olama_core_save_academic_year');
        echo '<input type="hidden" name="year_id" value="' . (int) ($year->id ?? 0) . '"><table class="form-table">';
        $this->field('code', __('الرمز المعتمد', 'olama-core'), $year->code ?? '', 'text', '2026-2027');
        $this->field('year_name', __('اسم العرض', 'olama-core'), $year->year_name ?? '', 'text', '2026-2027');
        $this->field('start_date', __('تاريخ البداية', 'olama-core'), $year->start_date ?? '', 'date');
        $this->field('end_date', __('تاريخ النهاية', 'olama-core'), $year->end_date ?? '', 'date');
        echo '</table>';
        submit_button($year ? __('تحديث السنة الأكاديمية', 'olama-core') : __('إضافة السنة الأكاديمية', 'olama-core'));
        echo '</form>';
    }

    private function render_source_mappings($years) {
        echo '<h3>' . esc_html__('رموز السنوات في الأنظمة الخارجية', 'olama-core') . '</h3>';
        echo '<p class="description">' . esc_html__('يحتفظ Core بالرمز المعتمد، بينما تستلم التكاملات الخارجية الرمز المرتبط هنا فقط.', 'olama-core') . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('السنة الأكاديمية في Core', 'olama-core') . '</th><th>' . esc_html__('رمز طلب Oracle', 'olama-core') . '</th><th>' . esc_html__('الإجراء', 'olama-core') . '</th></tr></thead><tbody>';
        foreach ($years as $year) {
            $canonical = $this->core->academic_calendar()->canonical_year_code($year->id);
            $mapping = $this->core->academic_calendar()->source_mapping($year->id, 'oracle');
            $external = $mapping ? $mapping->external_code : $canonical;
            $form_id = 'olama-core-oracle-year-' . (int) $year->id;
            echo '<tr><td><code>' . esc_html($canonical) . '</code></td><td><input form="' . esc_attr($form_id) . '" name="external_code" value="' . esc_attr($external) . '" placeholder="' . esc_attr($canonical) . '" aria-label="' . esc_attr(sprintf(__('Oracle year code for %s', 'olama-core'), $canonical)) . '"></td><td>';
            echo '<form id="' . esc_attr($form_id) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_core_save_year_source_mapping"><input type="hidden" name="academic_year_id" value="' . (int) $year->id . '"><input type="hidden" name="source_system" value="oracle">';
            wp_nonce_field('olama_core_save_year_source_mapping_' . $year->id);
            submit_button(__('حفظ ربط Oracle', 'olama-core'), 'secondary small', '', false);
            echo '</form></td></tr>';
        }
        echo '</tbody></table>';
    }

    private function render_semesters($year, $semesters, $current) {
        echo '<hr><h2>' . esc_html__('الفصول الدراسية', 'olama-core') . ($year ? ': ' . esc_html($year->code ?: $year->year_name) : '') . '</h2>';
        if (!$year) {
            echo '<p>' . esc_html__('أنشئ سنة أكاديمية أو اخترها أولاً.', 'olama-core') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>المعرّف</th><th>' . esc_html__('الاسم', 'olama-core') . '</th><th>' . esc_html__('التواريخ', 'olama-core') . '</th><th>' . esc_html__('الحالة', 'olama-core') . '</th><th>' . esc_html__('الإجراءات', 'olama-core') . '</th></tr></thead><tbody>';
        if (!$semesters) {
            echo '<tr><td colspan="5">' . esc_html__('لا توجد فصول معرفة لهذه السنة.', 'olama-core') . '</td></tr>';
        }
        foreach ($semesters as $semester) {
            $is_current = $current && (int) $current->semester_id === (int) $semester->id;
            echo '<tr><td>' . (int) $semester->id . '</td><td>' . esc_html($semester->semester_name) . '</td><td>' . esc_html($semester->start_date . ' → ' . $semester->end_date) . '</td><td>' . esc_html($is_current ? __('حالي', 'olama-core') : __('غير نشط', 'olama-core')) . '</td><td>';
            echo '<a class="button button-small" href="' . esc_url(add_query_arg(array('page' => 'olama-core-academic-calendar', 'year_id' => $year->id, 'edit_semester' => $semester->id), admin_url('admin.php'))) . '">' . esc_html__('تعديل', 'olama-core') . '</a></td></tr>';
        }
        echo '</tbody></table>';
    }

    private function render_semester_form($years, $semester, $selected_year_id) {
        echo '<h3>' . esc_html($semester ? __('تعديل الفصل الدراسي', 'olama-core') : __('إضافة فصل دراسي', 'olama-core')) . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_core_save_semester">';
        wp_nonce_field('olama_core_save_semester');
        echo '<input type="hidden" name="semester_id" value="' . (int) ($semester->id ?? 0) . '"><table class="form-table"><tr><th><label for="academic_year_id">' . esc_html__('السنة الأكاديمية', 'olama-core') . '</label></th><td><select id="academic_year_id" name="academic_year_id" required' . ($semester ? ' disabled' : '') . '>';
        foreach ($years as $year) {
            $value = $semester ? $semester->academic_year_id : $selected_year_id;
            printf('<option value="%d"%s>%s</option>', (int) $year->id, selected($value, $year->id, false), esc_html($year->code ?: $year->year_name));
        }
        echo '</select></td></tr>';
        $this->field('semester_name', __('اسم الفصل', 'olama-core'), $semester->semester_name ?? '');
        $this->field('start_date', __('تاريخ البداية', 'olama-core'), $semester->start_date ?? '', 'date');
        $this->field('end_date', __('تاريخ النهاية', 'olama-core'), $semester->end_date ?? '', 'date');
        echo '</table>';
        submit_button($semester ? __('تحديث الفصل', 'olama-core') : __('إضافة الفصل', 'olama-core'));
        echo '</form>';
    }

    private function field($name, $label, $value, $type = 'text', $placeholder = '') {
        printf(
            '<tr><th><label for="%1$s">%2$s</label></th><td><input class="regular-text" id="%1$s" name="%1$s" type="%3$s" value="%4$s" placeholder="%5$s" required></td></tr>',
            esc_attr($name), esc_html($label), esc_attr($type), esc_attr($value), esc_attr($placeholder)
        );
    }

    private function render_closeout($years, $current) {
        $closeout_year_id = absint($_GET['closeout_year_id'] ?? 0);
        echo '<hr><h2>' . esc_html__('إغلاق السنة والأرشيف الخاص', 'olama-core') . '</h2>';
        echo '<div class="notice notice-warning inline"><p>' . esc_html__('تغيير السنة الحالية لا يحذف البيانات. يتطلب الحذف أرشيفاً خاصاً تم التحقق منه، ولا يمكن الاستعادة إلا إلى نطاق سنة مغلقة وفارغة.', 'olama-core') . '</p></div>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '"><input type="hidden" name="page" value="olama-core-academic-calendar"><label for="closeout-year"><strong>' . esc_html__('السنة الأكاديمية المغلقة', 'olama-core') . '</strong></label> <select id="closeout-year" name="closeout_year_id"><option value="">' . esc_html__('اختر السنة', 'olama-core') . '</option>';
        foreach ($years as $year) {
            if ($current && (int) $current->academic_year_id === (int) $year->id) {
                continue;
            }
            printf('<option value="%d"%s>%s</option>', (int) $year->id, selected($closeout_year_id, $year->id, false), esc_html($year->code ?: $year->year_name));
        }
        echo '</select> '; submit_button(__('تشغيل المعاينة الآمنة', 'olama-core'), 'secondary', '', false); echo '</form>';

        if (!$closeout_year_id) {
            echo '<p class="description">' . esc_html__('الاحتفاظ: دون إجراء. الأرشفة: إنشاء نسخة خاصة والتحقق منها. الأرشفة والحذف: لا تتاح إلا بعد نجاح التحقق.', 'olama-core') . '</p>';
            return;
        }

        $preview = $this->core->year_closeout()->preview($closeout_year_id);
        if (is_wp_error($preview)) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($preview->get_error_message()) . '</p></div>';
            return;
        }

        echo '<h3>' . esc_html(sprintf(__('Dry preview for %s', 'olama-core'), $preview['year']->code ?: $preview['year']->year_name)) . '</h3>';
        echo '<p><strong>' . esc_html(sprintf(__('%d rows are classified for archive/purge.', 'olama-core'), $preview['purge_rows'])) . '</strong></p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Dataset', 'olama-core') . '</th><th>' . esc_html__('Table', 'olama-core') . '</th><th>' . esc_html__('Policy', 'olama-core') . '</th><th>' . esc_html__('Rows', 'olama-core') . '</th></tr></thead><tbody>';
        foreach ($preview['datasets'] as $dataset) {
            if (!$dataset['exists'] || (!$dataset['count'] && $dataset['purge'])) {
                continue;
            }
            echo '<tr><td>' . esc_html($dataset['label']) . '</td><td><code>' . esc_html($dataset['table']) . '</code></td><td>' . esc_html($dataset['purge'] ? __('Archive then purge', 'olama-core') : __('Preserve', 'olama-core')) . '</td><td>' . (int) $dataset['count'] . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h4>' . esc_html__('Always preserved', 'olama-core') . '</h4><ul style="list-style:disc;padding-left:22px">';
        foreach ($preview['preserved'] as $item) {
            echo '<li>' . esc_html($item) . '</li>';
        }
        echo '</ul>';

        if ($preview['blockers']) {
            echo '<div class="notice notice-error inline"><p><strong>' . esc_html__('Archive and purge are blocked by unclassified scoped tables:', 'olama-core') . '</strong></p><ul>';
            foreach ($preview['blockers'] as $blocker) {
                echo '<li><code>' . esc_html($blocker['table']) . '</code> — ' . (int) $blocker['count'] . ' ' . esc_html__('rows', 'olama-core') . '</li>';
            }
            echo '</ul></div>';
        } elseif ((int) $preview['purge_rows'] > 0) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_core_create_year_archive"><input type="hidden" name="year_id" value="' . (int) $closeout_year_id . '">';
            wp_nonce_field('olama_core_create_year_archive');
            submit_button(__('Create private archive', 'olama-core'), 'primary', '', false);
            echo '</form>';
        } else {
            echo '<p class="description">' . esc_html__('No classified historical rows remain. Use the purged archive below if restoration is required.', 'olama-core') . '</p>';
        }

        $archives = $this->core->year_closeout()->archives($closeout_year_id);
        if (!$archives) {
            return;
        }
        echo '<h3>' . esc_html__('Archive history', 'olama-core') . '</h3><table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__('Status', 'olama-core') . '</th><th>' . esc_html__('Rows', 'olama-core') . '</th><th>' . esc_html__('Created', 'olama-core') . '</th><th>' . esc_html__('Actions', 'olama-core') . '</th></tr></thead><tbody>';
        foreach ($archives as $archive) {
            echo '<tr><td>#' . (int) $archive->id . '</td><td>' . esc_html($archive->status) . '</td><td>' . (int) $archive->total_rows . '</td><td>' . esc_html($archive->created_at) . '</td><td>';
            if ('created' === $archive->status || 'verified' === $archive->status) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px"><input type="hidden" name="action" value="olama_core_verify_year_archive"><input type="hidden" name="archive_id" value="' . (int) $archive->id . '">';
                wp_nonce_field('olama_core_verify_year_archive_' . $archive->id);
                submit_button(__('Verify archive', 'olama-core'), 'secondary small', '', false);
                echo '</form>';
            }
            if (in_array((string) $archive->status, array('verified', 'restored'), true)) {
                echo '<details><summary style="cursor:pointer;color:#b32d2e">' . esc_html__('Archive and purge historical rows', 'olama-core') . '</summary><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_core_purge_year_archive"><input type="hidden" name="archive_id" value="' . (int) $archive->id . '">';
                wp_nonce_field('olama_core_purge_year_archive_' . $archive->id);
                echo '<p><label>' . esc_html__('Type the exact year code:', 'olama-core') . ' <input name="confirmation" autocomplete="off" required></label></p><p><label><input type="checkbox" name="understand" value="1" required> ' . esc_html__('I understand that the classified historical transactions will be removed from the live database.', 'olama-core') . '</label></p>';
                submit_button(__('Purge verified historical rows', 'olama-core'), 'delete', '', false);
                echo '</form></details>';
            }
            if ('purged' === $archive->status) {
                echo '<details><summary style="cursor:pointer;color:#135e96">' . esc_html__('Restore archived historical rows', 'olama-core') . '</summary><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_core_restore_year_archive"><input type="hidden" name="archive_id" value="' . (int) $archive->id . '">';
                wp_nonce_field('olama_core_restore_year_archive_' . $archive->id);
                echo '<p>' . esc_html__('Restore re-verifies every checksum and imports parent datasets before their children. Any conflict rolls back the entire operation.', 'olama-core') . '</p><p><label>' . esc_html__('Type the exact year code:', 'olama-core') . ' <input name="confirmation" autocomplete="off" required></label></p><p><label><input type="checkbox" name="understand" value="1" required> ' . esc_html__('I understand that the archived historical transactions will be inserted back into the live database.', 'olama-core') . '</label></p>';
                submit_button(__('Restore verified archive', 'olama-core'), 'secondary', '', false);
                echo '</form></details>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public function save_year() {
        $this->authorize_post('olama_core_save_academic_year');
        $year_id = absint($_POST['year_id'] ?? 0);
        $data = array(
            'code' => sanitize_text_field(wp_unslash($_POST['code'] ?? '')),
            'year_name' => sanitize_text_field(wp_unslash($_POST['year_name'] ?? '')),
            'start_date' => sanitize_text_field(wp_unslash($_POST['start_date'] ?? '')),
            'end_date' => sanitize_text_field(wp_unslash($_POST['end_date'] ?? '')),
        );
        $result = $year_id ? $this->core->academic_calendar()->update_year($year_id, $data) : $this->core->academic_calendar()->create_year($data);
        $this->redirect_result($result, $year_id ? __('Academic year updated.', 'olama-core') : __('Academic year created.', 'olama-core'));
    }

    public function delete_year() {
        $this->authorize_post('olama_core_delete_academic_year');
        $result = $this->core->academic_calendar()->delete_year(absint($_POST['year_id'] ?? 0));
        $this->redirect_result($result, __('Academic year deleted.', 'olama-core'));
    }

    public function save_semester() {
        $this->authorize_post('olama_core_save_semester');
        $semester_id = absint($_POST['semester_id'] ?? 0);
        $existing = $semester_id ? $this->core->academic_calendar()->semester($semester_id) : null;
        $data = array(
            'academic_year_id' => $existing ? $existing->academic_year_id : absint($_POST['academic_year_id'] ?? 0),
            'semester_name' => sanitize_text_field(wp_unslash($_POST['semester_name'] ?? '')),
            'start_date' => sanitize_text_field(wp_unslash($_POST['start_date'] ?? '')),
            'end_date' => sanitize_text_field(wp_unslash($_POST['end_date'] ?? '')),
        );
        $result = $semester_id ? $this->core->academic_calendar()->update_semester($semester_id, $data) : $this->core->academic_calendar()->create_semester($data);
        $this->redirect_result($result, $semester_id ? __('Semester updated.', 'olama-core') : __('Semester created.', 'olama-core'), $data['academic_year_id']);
    }

    public function delete_semester() {
        $this->authorize_post('olama_core_delete_semester');
        $result = $this->core->academic_calendar()->delete_semester(absint($_POST['semester_id'] ?? 0));
        $this->redirect_result($result, __('Semester deleted.', 'olama-core'));
    }

    public function set_context() {
        $this->authorize_post('olama_core_set_academic_context');
        $result = $this->core->academic_context()->set_context(absint($_POST['academic_year_id'] ?? 0), absint($_POST['semester_id'] ?? 0));
        $this->redirect_result($result, __('Active academic year and semester updated.', 'olama-core'), absint($_POST['academic_year_id'] ?? 0));
    }

    public function save_year_source_mapping() {
        $year_id = absint($_POST['academic_year_id'] ?? 0);
        $this->authorize_post('olama_core_save_year_source_mapping_' . $year_id);
        $source = sanitize_key(wp_unslash($_POST['source_system'] ?? ''));
        $external_code = sanitize_text_field(wp_unslash($_POST['external_code'] ?? ''));
        $result = $this->core->academic_calendar()->save_source_mapping($year_id, $source, $external_code);
        $this->redirect_result($result, __('External academic-year mapping updated.', 'olama-core'), $year_id);
    }

    public function create_year_archive() {
        $this->authorize_post('olama_core_create_year_archive');
        $year_id = absint($_POST['year_id'] ?? 0);
        $result = $this->core->year_closeout()->create_archive($year_id);
        $this->redirect_closeout($result, __('Private year archive created. Verify it before purge.', 'olama-core'), $year_id);
    }

    public function verify_year_archive() {
        $archive_id = absint($_POST['archive_id'] ?? 0);
        $this->authorize_post('olama_core_verify_year_archive_' . $archive_id);
        $archive = $this->core->year_closeout()->archives();
        $year_id = 0;
        foreach ($archive as $item) {
            if ((int) $item->id === $archive_id) {
                $year_id = (int) $item->academic_year_id;
                break;
            }
        }
        $result = $this->core->year_closeout()->verify_archive($archive_id);
        $this->redirect_closeout($result, __('Archive checksums and row counts verified.', 'olama-core'), $year_id);
    }

    public function purge_year_archive() {
        $archive_id = absint($_POST['archive_id'] ?? 0);
        $this->authorize_post('olama_core_purge_year_archive_' . $archive_id);
        if (empty($_POST['understand'])) {
            $this->redirect_closeout(new WP_Error('year_purge_acknowledgement', __('Purge acknowledgement is required.', 'olama-core')), '', 0);
        }
        $archives = $this->core->year_closeout()->archives();
        $year_id = 0;
        foreach ($archives as $item) {
            if ((int) $item->id === $archive_id) {
                $year_id = (int) $item->academic_year_id;
                break;
            }
        }
        $confirmation = sanitize_text_field(wp_unslash($_POST['confirmation'] ?? ''));
        $result = $this->core->year_closeout()->purge_verified_archive($archive_id, $confirmation);
        $this->redirect_closeout($result, __('Verified historical rows purged. Preservation data was not changed.', 'olama-core'), $year_id);
    }

    public function restore_year_archive() {
        $archive_id = absint($_POST['archive_id'] ?? 0);
        $this->authorize_post('olama_core_restore_year_archive_' . $archive_id);
        if (empty($_POST['understand'])) {
            $this->redirect_closeout(new WP_Error('year_restore_acknowledgement', __('Restore acknowledgement is required.', 'olama-core')), '', 0);
        }
        $archives = $this->core->year_closeout()->archives();
        $year_id = 0;
        foreach ($archives as $item) {
            if ((int) $item->id === $archive_id) {
                $year_id = (int) $item->academic_year_id;
                break;
            }
        }
        $confirmation = sanitize_text_field(wp_unslash($_POST['confirmation'] ?? ''));
        $result = $this->core->year_closeout()->restore_purged_archive($archive_id, $confirmation);
        $this->redirect_closeout($result, __('Historical rows restored and recounted. The active academic context was not changed.', 'olama-core'), $year_id);
    }

    private function authorize() {
        if (!current_user_can('manage_olama_academic_context') && !current_user_can('manage_options')) {
            wp_die(esc_html__('You cannot manage the academic calendar.', 'olama-core'));
        }
    }

    private function authorize_post($nonce_action) {
        $this->authorize();
        check_admin_referer($nonce_action);
    }

    private function redirect_result($result, $success, $year_id = 0) {
        $args = array('page' => 'olama-core-academic-calendar');
        if ($year_id) {
            $args['year_id'] = absint($year_id);
        }
        if (is_wp_error($result)) {
            $args['olama_core_error'] = $result->get_error_message();
        } else {
            $args['olama_core_notice'] = $success;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private function redirect_closeout($result, $success, $year_id) {
        $args = array('page' => 'olama-core-academic-calendar', 'closeout_year_id' => absint($year_id));
        if (is_wp_error($result)) {
            $args['olama_core_error'] = $result->get_error_message();
        } else {
            $args['olama_core_notice'] = $success;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private function render_notice() {
        if (!empty($_GET['olama_core_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['olama_core_error']))) . '</p></div>';
        } elseif (!empty($_GET['olama_core_notice'])) {
            echo '<div class="notice notice-success"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['olama_core_notice']))) . '</p></div>';
        }
    }
}
