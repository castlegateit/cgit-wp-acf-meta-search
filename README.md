# Castlegate IT WP ACF Meta Search

**This plugin is no longer maintained. The [ACF Better Search](https://wordpress.org/plugins/acf-better-search/) plugin is now recommended instead.**

***

Adds support for searching meta values in the Advanced Custom Fields plugin. Fields can only searchable when registering custom field groups via PHP.

# Usage

To enable searching for a specific field, add a new index called `searchable` to your field array, and set a boolean value of `true`. Setting `searchable` to `false` or omitting the `searchable` index will exclude the field from searches.

~~~ php
$group = [
    'key' => 'example_group',
    'title' => 'Example Group',
    'fields' => [
        [
            'key' => 'example',
            'name' => 'example',
            'type' => 'textarea',
            'searchable' => true,
        ],
    ],
];
acf_add_local_field_group($group);
~~~

# Excerpts

An excerpt method exists which allows you to provide more useful excerpts based on custom field values, rather than just post content. A standard excerpt does not include custom fields, and so it may look irrelevant in the search results.

~~~ php
if (have_posts()) {
    while (have_posts()) {
        the_post();
        echo '<h1>' . get_the_title() . '</h3>';
        echo \Cgit\AcfMetaSearch::excerpt($post->ID, get_the_excerpt());
    }
}
~~~

# Notes

When changing templates in ACF, custom field data remains for your old template's custom fields. This can have a side effect of showing irrelevant results if old data is stored but no longer displayed on the page.

## License

Released under the [MIT License](https://opensource.org/licenses/MIT). See [LICENSE](LICENSE) for details.
