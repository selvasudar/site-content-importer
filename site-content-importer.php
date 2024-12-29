<?php
/*
Plugin Name: Site Content Importer
Description: Import content from any website into WordPress posts/pages
Version: 1.0
Author: Selvakumar Duraipandian
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Add menu item
add_action('admin_menu', 'sci_add_admin_menu');
function sci_add_admin_menu() {
    add_menu_page(
        'Site Content Importer',
        'Site Importer',
        'manage_options',
        'site-content-importer',
        'sci_admin_page',
        'dashicons-download'
    );
}

// Create the admin page HTML
function sci_admin_page() {
    ?>
    <div class="wrap">
        <h1>Site Content Importer</h1>
        
        <div class="card">
            <form method="post" action="">
                <?php wp_nonce_field('sci_import_action', 'sci_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="sitemap_url">Sitemap URL</label></th>
                        <td>
                            <input type="url" name="sitemap_url" id="sitemap_url" class="regular-text" required>
                            <p class="description">Enter the full URL of the sitemap (e.g., https://example.com/sitemap.xml)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="post_type">Import As</label></th>
                        <td>
                            <select name="post_type" id="post_type">
                                <option value="post">Posts</option>
                                <option value="page">Pages</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="post_status">Post Status</label></th>
                        <td>
                            <select name="post_status" id="post_status">
                                <option value="draft">Draft</option>
                                <option value="publish">Published</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="sci_import" class="button button-primary" value="Start Import">
                </p>
            </form>
        </div>
        
        <div id="import-progress" style="display: none;">
            <h2>Import Progress</h2>
            <div class="progress-bar">
                <div class="progress-bar-fill" style="width: 0%"></div>
            </div>
            <p class="progress-status"></p>
        </div>
    </div>
    
    <style>
        .progress-bar {
            width: 100%;
            height: 20px;
            background-color: #f0f0f0;
            border-radius: 10px;
            overflow: hidden;
            margin: 10px 0;
        }
        .progress-bar-fill {
            height: 100%;
            background-color: #2271b1;
            transition: width 0.3s ease-in-out;
        }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        $('form').on('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'sci_import_content');
            
            $('#import-progress').show();
            $('.progress-status').text('Starting import...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        $('.progress-status').html(
                            `Import completed!<br>` +
                            `Successfully imported: ${response.data.success}<br>` +
                            `Failed: ${response.data.failed}`
                        );
                    } else {
                        $('.progress-status').text('Import failed: ' + response.data.message);
                    }
                },
                error: function() {
                    $('.progress-status').text('Import failed due to server error');
                }
            });
        });
    });
    </script>
    <?php
}

// Handle AJAX import request
add_action('wp_ajax_sci_import_content', 'sci_handle_import');
function sci_handle_import() {
    check_ajax_referer('sci_import_action', 'sci_nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }
    
    $sitemap_url = $_POST['sitemap_url'];
    $post_type = $_POST['post_type'];
    $post_status = $_POST['post_status'];
    
    // Initialize the importer class
    $importer = new SCI_Importer();
    $result = $importer->import($sitemap_url, $post_type, $post_status);
    
    wp_send_json_success($result);
}

// Importer class
class SCI_Importer {
    private function get_urls_from_sitemap($sitemap_url) {
        $urls = [];
        
        try {
            $response = wp_remote_get($sitemap_url);
            if (is_wp_error($response)) {
                throw new Exception('Failed to fetch sitemap');
            }
            
            $xml = simplexml_load_string(wp_remote_retrieve_body($response));
            if (!$xml) {
                throw new Exception('Invalid sitemap XML');
            }
            
            // Handle both standard sitemaps and sitemap index files
            if (strpos($xml->getName(), 'sitemapindex') !== false) {
                foreach ($xml->sitemap as $sitemap) {
                    $sub_urls = $this->get_urls_from_sitemap((string)$sitemap->loc);
                    $urls = array_merge($urls, $sub_urls);
                }
            } else {
                foreach ($xml->url as $url) {
                    $urls[] = (string)$url->loc;
                }
            }
        } catch (Exception $e) {
            error_log('Sitemap parsing error: ' . $e->getMessage());
        }
        
        return $urls;
    }
    
    private function scrape_content($url) {
        try {
            $response = wp_remote_get($url);
            if (is_wp_error($response)) {
                throw new Exception('Failed to fetch URL');
            }
            
            $html = wp_remote_retrieve_body($response);
            
            // Use DOMDocument for parsing
            $doc = new DOMDocument();
            @$doc->loadHTML($html, LIBXML_NOERROR);
            
            // Remove scripts, styles, and other unwanted elements
            $unwanted_tags = ['script', 'style', 'iframe', 'header', 'footer', 'nav'];
            foreach ($unwanted_tags as $tag) {
                $elements = $doc->getElementsByTagName($tag);
                $remove = [];
                foreach ($elements as $element) {
                    $remove[] = $element;
                }
                foreach ($remove as $element) {
                    $element->parentNode->removeChild($element);
                }
            }
            
            // Get title
            $titles = $doc->getElementsByTagName('title');
            $title = $titles->length > 0 ? $titles->item(0)->textContent : '';
            
            // Get main content
            $main = $doc->getElementsByTagName('main');
            if ($main->length > 0) {
                $content = $main->item(0)->C14N();
            } else {
                $article = $doc->getElementsByTagName('article');
                if ($article->length > 0) {
                    $content = $article->item(0)->C14N();
                } else {
                    $content = $doc->getElementsByTagName('body')->item(0)->C14N();
                }
            }
            
            return [
                'title' => sanitize_text_field($title),
                'content' => wp_kses_post($content)
            ];
            
        } catch (Exception $e) {
            error_log('Content scraping error: ' . $e->getMessage());
            return false;
        }
    }
    
    public function import($sitemap_url, $post_type, $post_status) {
        $urls = $this->get_urls_from_sitemap($sitemap_url);
        
        $results = [
            'success' => 0,
            'failed' => 0
        ];
        
        foreach ($urls as $url) {
            $content = $this->scrape_content($url);
            
            if ($content) {
                // Create post
                $post_data = [
                    'post_title' => $content['title'],
                    'post_content' => $content['content'],
                    'post_status' => $post_status,
                    'post_type' => $post_type,
                    'meta_input' => [
                        'source_url' => $url
                    ]
                ];
                
                $post_id = wp_insert_post($post_data);
                
                if (!is_wp_error($post_id)) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                }
            } else {
                $results['failed']++;
            }
        }
        
        return $results;
    }
}