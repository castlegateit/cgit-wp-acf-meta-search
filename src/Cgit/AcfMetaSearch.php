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
    public static $searchable_fields = [];

    /**
     * Searchable meta_key values.
     *
     * @var array
     */
    public static $meta_keys = [];

    /**
     * Constructor.
     */
    private function __construct()
    {
        $this->buildFieldList();
        $this->buildMetaKeyList();
        $this->checkSearch();
        $this->applyFilters();
    }

    /**
     * Return instance.
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
     * Build a list of all searchable fields from ACF.
     */
    private function buildFieldList()
    {
        // Ensure the ACF functions are available.
        if (!function_exists('acf_local') || !isset(acf_local()->fields)) {
            return;
        }

        // Available fields.
        $this->fields = acf_local()->fields;

        // Filter down to searchable fields only.
        self::$searchable_fields = array_filter(
            $this->fields,
            function ($a) {
                return isset($a['searchable']) && $a['searchable'];
            }
        );
    }

    /**
     * Takes the ACF field array and constructs a regular expression to match
     * each searchable field in the postmeta table. ACF stores each field name
     * individually, however repeaters and nested repeaters create meta_keys
     * which combine the field key, and parent keys such as:
     *
     * parent_name_0_subfield_name_0_another_subfield.
     *
     * A regular expression is created to match such meta_keys and all unique
     * searchable meta keys are queried from the database.
     *
     * @return void
     */
    private function buildMetaKeyList()
    {
        // Build the regular expressions for each field.
        foreach (self::$searchable_fields as $key => $field) {
            self::$searchable_fields[$key]['regex'] = '^'.$this->getMetaKeyRegex($field) . '$';
        }

        // Find a true list of all available searchable meta_key values.
        global $wpdb;

        $sql = "SELECT DISTINCT meta_key FROM " . $wpdb->prefix . "postmeta WHERE ";

        foreach (self::$searchable_fields as $field) {
            $sql.= "meta_key REGEXP '" . $field['regex'] . "' OR ";
        }

        $sql = substr($sql, 0, -4);

        $results = $wpdb->get_results($sql);

        // Take the query results and populate an of searchable meta_keys
        self::$meta_keys = array_map(
            function ($a) {
                return $a->meta_key;
            },
            $results
        );
    }

    /**
     * Applies any necessary filters.
     */
    private function applyFilters()
    {
        $this->filterDistinct();
        $this->filterMetaJoin();
        $this->filterSearch();
    }

    /**
     * Filters the search query DISTINCT segment. The customised search results
     * in duplicate IDs in the results so DISTINCT must be set.
     */
    private function filterDistinct()
    {
        add_filter(
            'posts_distinct',
            function ($sql) {
                if ($this->is_search) {
                    if (empty($sql)) {
                        return " DISTINCT ";
                    }
                }
                return $sql;
            }
        );
    }

    /**
     * Filter the query to add a join to the post_meta table.
     */
    private function filterMetaJoin()
    {
        add_filter(
            'posts_join_paged',
            function ($sql) {
                global $wpdb;

                if ($this->is_search) {
                    return $sql . ' INNER JOIN ' . $wpdb->prefix
                        . 'postmeta AcfMetaSearch ON (' . $wpdb->prefix
                        . 'posts.ID = AcfMetaSearch.post_id)';
                }
                return $sql;
            }
        );
    }

    /**
     * Filter the search query SQL to include meta query matching.
     *
     * @return void
     */
    private function filterSearch()
    {
        global $wpdb;

        // Set the WordPress prefix.
        $prefix = $wpdb->prefix;

        // Placeholder used for swapping the search term during SQL query
        // building.
        $placeholder = 'AcfSearchPlaceholder-'.md5(microtime());

        // Build an array of meta query SQL segments.
        $where = [];
        foreach (self::$meta_keys as $field) {
            $temp = "AcfMetaSearch.meta_key = '".$field."' ";
            $temp.= "AND CAST(AcfMetaSearch.meta_value AS CHAR) LIKE '%".$placeholder."%'";
            $where[] = $temp;
        }

        // Filter the search query.
        $func = function ($sql) use ($where, $prefix, $placeholder) {

            // Check this query is a search.
            if (!$this->is_search) {
                return $sql;
            }

            // Extract the search terms from the SQL.
            $search_terms = $this->getSearchTermsFromSql($sql);

            // Abort if there are no search terms.
            if (!count($search_terms)) {
                return $sql;
            }

            $new_sql = "AND \n";
            $new_sql.= "(\n";

            $i = 0;
            $keyword_count = count($search_terms);

            foreach ($search_terms as $keyword) {
                $i++;

                $new_sql.= "\t(\n";

                foreach ($where as $meta) {
                    $new_sql.= "\t\t(".str_replace($placeholder, $keyword, $meta).")\n";
                    $new_sql.= "\t\tOR\n";
                }

                $new_sql.= "\t\t(".$prefix."posts.post_excerpt LIKE '%".$keyword."%')\n\t\tOR\n";
                $new_sql.= "\t\t(".$prefix."posts.post_content LIKE '%".$keyword."%')\n";
                $new_sql.= "\t)\n";

                if ($i != $keyword_count) {
                    $new_sql.= "\tAND\n";
                }
            }

            $new_sql.= ")\n";
            $new_sql.= "AND\n";
            $new_sql.= "(\n\t".$prefix."posts.post_password = ''\n)";

            return $new_sql;
        };

        add_filter('posts_search', $func);
    }

    /**
     * Accepts the original WordPress search SQL WHERE clause and extracts the
     * search terms.
     *
     * @param string $sql
     *
     * @return array
     */
    private function getSearchTermsFromSql($sql)
    {
        // Extract the search term as already used by WordPress in its SQL.
        $preg = preg_match_all('/\'(?:\%|\{[a-zA-Z0-9]+\})([^\%\{\}]*)(?:\{[a-zA-Z0-9]+\}|\%)\'/', $sql, $matches);

        return array_unique(end($matches));
    }

    /**
     * Checks if a query is a search. The plugin filters many other segments
     * of queries in their later stages, which should only happen after a check
     * that the query is actually a search query.
     */
    private function checkSearch()
    {
        add_filter(
            'pre_get_posts',
            function ($query) {
                $this->is_search = $query->is_search();
            }
        );
    }

    /**
     * Recursively build a meta_key regular expression by appending parent
     * field names to each field.
     *
     * @param array $field
     *
     * @return string
     */
    private function getMetaKeyRegex($field)
    {
        $parent = $field['parent'];

        if (array_key_exists($parent, $this->fields) && $this->fields[$parent]['type'] == 'repeater') {
            return $this->getMetaKeyRegex($this->fields[$parent]) . '_[0-9]+_' . $field['key'];
        } else {
            return $field['key'];
        }
    }

    /**
     * The search excerpt returns a suitable excerpt for a given page, based on
     * searchable custom meta values. Text based fields are stitched together into
     * one string, starting with the largest field which includes the search term.
     * The excerpt is trimmed to the $length, without stopping in the middle of a
     * word.
     *
     * @param  int $post_id
     * @param  string $excerpt Original excerpt
     * @param  string $end
     * @param  int $length
     * @return string
     */
    public static function excerpt($post_id, $excerpt, $end = '...', $length = 250)
    {
        // Requires the acf function get_fields();
        if (!function_exists('get_fields')) {
            return '';
        }

        // Get available fields for this post.
        $fields_available = get_fields($post_id);

        // Ensure it is an array.
        $fields_available = is_array($fields_available) ? $fields_available : [];

        // Get searchable fields.
        $fields_searchable = array_keys(self::$searchable_fields);

        // Final fields to include in the excerpt.
        $fields = [];

        // Reduce fields to searchable only.
        foreach ($fields_available as $key => $field) {
            if (in_array($key, $fields_searchable)) {
                $fields[$key] = $field;
            }
        }

        // Store matches with the search term.
        $field_hits = [];

        // Find matches.
        foreach ($fields as $key => $field) {
            if (is_string($field) && stristr($field, get_search_query())) {
                $field_hits[$key] = strlen($field);
            } else {
                $field_hits[$key] = 0;
            }
        }

        if ($fields) {
            // Move the biggest hit to the front of the array.
            while (reset($fields) !== max($fields)) {
                $keys = array_keys($fields);
                $val = $fields[$keys[0]];

                unset($fields[$keys[0]]);
                $fields[$keys[0]] = $val;
            }
        }

        // Add the original excerpt.
        $fields['_cgit_wp_acf_meta_search_excerpt'] = $excerpt;

        $new_excerpt = '';
        foreach ($fields as $field) {
            $new_excerpt.= ' '.strip_tags($field);
            $new_excerpt = trim($new_excerpt, '\t\n\r\0\x0B.');
        }

        // Find the first end of word after a given $length.
        if (preg_match('/^.{1,'. $length .'}\b/s', $new_excerpt, $match)) {
            return trim($match[0], '\t\n\r\0\x0B.').$end;
        }

        return '';
    }
}
