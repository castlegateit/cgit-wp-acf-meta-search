<?php

namespace Cgit;

class AcfMetaSearch
{
    /**
     * Singleton instance.
     *
     * @var null
     */
    private static $instance;

    /**
     * Available ACF fields.
     *
     * @var array
     */
    private $fields = [];

    /**
     * Searchable ACF fields.
     *
     * @var array
     */
    private $search_fields = [];

    /**
     * Constructor.
     *
     * @return void
     */
    private function __construct()
    {
        $this->buildFieldList();
        $this->applyFilters();
    }

    /**
     * Return instance
     *
     * return Cgit\AcfMetaSearch
     */
    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Build a list of all searchable fields.
     *
     * @return void
     */
    private function buildFieldList()
    {
        // Ensure the ACF functions are available
        if (!function_exists('acf_local') || !isset(acf_local()->fields)) {
            return;
        }

        // Available fields
        $this->fields = acf_local()->fields;

        // Searchable fields
        $searchable_fields = array_filter(
            $this->fields,
            function ($a) {
                if (isset($a['searchable']) && $a['searchable']) {
                    return true;
                }
            }
        );

        // Extract field names
        $this->searchable_fields = array_values(
            array_map(
                function ($a) {
                    return $a['name'];
                },
                $searchable_fields
            )
        );
    }

    /**
     * Applies any necessary filters.
     *
     * @return void
     */
    private function applyFilters()
    {
        $this->filterSearchQuery();
        $this->filterMetaWhere();
    }

    /**
     * Filters the WordPress search query to include meta_values.
     *
     * @return void
     */
    private function filterSearchQuery()
    {
        $func = function ($query) {
            if (!is_admin() && $query->is_search) {
                $meta = ['relation' => 'OR'];

                // Add each field
                foreach ($this->searchable_fields as $field) {
                    $meta[] = [
                        'key' => $field,
                        'value' => $query->query_vars['s'],
                        'compare' => 'LIKE',
                        'type' => 'CHAR',
                    ];
                }

                $query->set('meta_query', $meta);
            }

            return $query;
        };

        add_filter('pre_get_posts', $func);
    }

    /**
     * Filters the search meta query SQL to change the 'AND' clause into an 'OR'
     * clause. By default any meta query is appended using 'AND'.
     *
     * @return void
     */
    private function filterMetaWhere()
    {
        $func = function ($sql) {
            if (is_search()) {
                $sql['where'] = preg_replace('/^\s+AND\s+/', ' OR ', $sql['where']);
            }
            return $sql;
        };

        add_filter('get_meta_sql', $func);
    }
}
