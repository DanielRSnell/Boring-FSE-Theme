<?php

class ScssCompiler
{
    private $scss_dir;
    private $css_dir;
    private $has_error = false;
    private $error_message = '';
    private $css_filename = 'main.css';
    private $entry_file = 'main.scss';

    public function __construct()
    {
        $this->scss_dir = get_stylesheet_directory() . '/src/assets/sass';
        $this->css_dir = get_stylesheet_directory() . '/css';

        require_once get_stylesheet_directory() . '/vendor/autoload.php';

        add_action('admin_notices', [$this, 'display_admin_notice']);
        add_action('after_switch_theme', [$this, 'compile']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles'], 1);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_styles'], 1);
        add_action('admin_bar_menu', [$this, 'add_toolbar_compile_button'], 100);
        add_action('admin_init', [$this, 'handle_compile_request']);
    }

    public function init()
    {
        if (!class_exists('ScssPhp\ScssPhp\Compiler')) {
            $this->set_error('SCSS Compiler not found. Please run: composer require scssphp/scssphp');
            return;
        }

        $entry_path = $this->scss_dir . '/' . $this->entry_file;

        if (!is_dir($this->scss_dir)) {
            $this->set_error("SCSS directory not found: {$this->scss_dir}");
            return;
        }

        if (!file_exists($entry_path)) {
            $this->set_error("Main SCSS file not found: {$entry_path}");
            return;
        }

        if (!is_dir($this->css_dir)) {
            if (!mkdir($this->css_dir, 0755, true)) {
                $this->set_error("Failed to create CSS directory: {$this->css_dir}");
                return;
            }
        }
    }

    public function handle_compile_request()
    {
        if (
            current_user_can('manage_options') &&
            isset($_GET['action']) &&
            $_GET['action'] === 'compile_scss' &&
            isset($_GET['_wpnonce']) &&
            wp_verify_nonce($_GET['_wpnonce'], 'compile_scss')
        ) {
            if ($this->compile()) {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-success"><p>SCSS compiled successfully!</p></div>';
                });
            }
            wp_redirect(remove_query_arg(['action', '_wpnonce']));
            exit;
        }
    }

    public function add_toolbar_compile_button($admin_bar)
    {
        if (current_user_can('manage_options')) {
            $args = [
                'id' => 'compile-scss',
                'title' => 'Compile SCSS',
                'href' => wp_nonce_url(
                    add_query_arg('action', 'compile_scss', admin_url()),
                    'compile_scss'
                ),
            ];
            $admin_bar->add_node($args);
        }
    }

    public function compile()
    {
        if ($this->has_error) {
            return false;
        }

        try {
            $entry_path = $this->scss_dir . '/' . $this->entry_file;

            $scss = file_get_contents($entry_path);
            if ($scss === false) {
                throw new Exception("Failed to read entry file: {$entry_path}");
            }

            $compiler = new ScssPhp\ScssPhp\Compiler();

            $compiler->setImportPaths([
                $this->scss_dir,
                $this->scss_dir . '/bootstrap',
                $this->scss_dir . '/theme',
                $this->scss_dir . '/woocommerce',
            ]);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                $compiler->setSourceMap(ScssPhp\ScssPhp\Compiler::SOURCE_MAP_INLINE);
            }

            $css = $compiler->compile($scss);
            $output_file = $this->css_dir . '/' . $this->css_filename;

            if (file_put_contents($output_file, $css) === false) {
                throw new Exception("Failed to write CSS file: {$output_file}");
            }

            update_option('scss_last_compile_time', time());

            return true;
        } catch (Exception $e) {
            $this->set_error("SCSS Compilation failed: " . $e->getMessage());
            return false;
        }
    }

    public function enqueue_styles()
    {
        if (!$this->has_error) {
            $css_file = $this->css_dir . '/' . $this->css_filename;
            if (file_exists($css_file)) {
                wp_enqueue_style(
                    'child-theme-styles',
                    get_stylesheet_directory_uri() . '/css/' . $this->css_filename,
                    [],
                    get_option('scss_last_compile_time', filemtime($css_file)),
                    'all'
                );
            }
        }
    }

    public function enqueue_editor_styles()
    {
        if (!$this->has_error) {
            $css_file = $this->css_dir . '/' . $this->css_filename;
            if (file_exists($css_file)) {
                wp_enqueue_style(
                    'child-theme-editor-styles',
                    get_stylesheet_directory_uri() . '/css/' . $this->css_filename,
                    [],
                    get_option('scss_last_compile_time', filemtime($css_file)),
                    'all'
                );
            }
        }
    }

    private function set_error($message)
    {
        $this->has_error = true;
        $this->error_message = $message;
    }

    public function display_admin_notice()
    {
        if ($this->has_error) {
            echo '<div class="notice notice-error"><p>SCSS Compiler Error: ' . esc_html($this->error_message) . '</p></div>';
        }
    }
}

$scss_compiler = new ScssCompiler();
$scss_compiler->init();
