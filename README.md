# Castlegate IT WP ACF Meta Search

Adds support for searching meta values in the Advanced Custom Fields plugin. Fields can only searchable when registering custom field groups via PHP.

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



