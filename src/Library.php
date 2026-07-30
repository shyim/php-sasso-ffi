<?php

declare(strict_types=1);

namespace Sasso;

use FFI;

/**
 * Owns the FFI handle to libsasso.
 *
 * The declarations below are sasso.h with the preprocessor removed, since
 * FFI::cdef() parses C declarations only — no #include, #define, or #ifdef.
 * They must stay byte-compatible with the header: the struct field order is
 * ABI, not documentation.
 */
final class Library
{
    private const HEADER = <<<'CDEF'
        typedef struct SassoCanonicalizeContext {
          int32_t from_import;
          const char *containing_url;
        } SassoCanonicalizeContext;

        typedef struct SassoImporterSink SassoImporterSink;

        typedef struct SassoImporter {
          void *user_data;
          int32_t (*canonicalize)(void *user_data, const char *url,
                                  const SassoCanonicalizeContext *ctx,
                                  SassoImporterSink *sink);
          int32_t (*load)(void *user_data, const char *canonical, SassoImporterSink *sink);
        } SassoImporter;

        typedef struct SassoOptions {
          uint32_t struct_size;
          int32_t style;
          int32_t syntax;
          int32_t unicode;
          const char *url;
          const char *const *load_paths;
          size_t load_paths_len;
          const SassoImporter *importer;
        } SassoOptions;

        typedef struct SassoResult {
          int32_t ok;
          char *css;
          size_t css_len;
          char *error;
          size_t error_len;
          uint32_t error_line;
          uint32_t error_column;
        } SassoResult;

        const char *sasso_version(void);
        void sasso_options_init(SassoOptions *options, size_t struct_size);
        SassoResult *sasso_compile(const char *source, size_t source_len, const SassoOptions *options);
        void sasso_result_free(SassoResult *result);
        void sasso_importer_set_canonical(SassoImporterSink *sink, const char *ptr, size_t len);
        void sasso_importer_set_result(SassoImporterSink *sink,
                                       const char *contents, size_t contents_len,
                                       int32_t syntax,
                                       const char *source_map_url, size_t source_map_url_len);
        void sasso_importer_set_error(SassoImporterSink *sink, const char *ptr, size_t len);
        CDEF;

    private static ?self $default = null;

    private function __construct(
        private readonly FFI $ffi,
        private readonly string $path,
    ) {
    }

    /**
     * The process-wide library handle, loaded on first use.
     *
     * Sharing one handle matters: each FFI::cdef() maps the shared object
     * again, and sasso's compiles are self-contained, so there is nothing to
     * gain from separate handles.
     */
    public static function default(): self
    {
        return self::$default ??= self::load();
    }

    /**
     * Load libsasso, downloading it if the Composer plugin never ran.
     *
     * @param string|null $path explicit library to load; defaults to
     *                          $SASSO_LIBRARY, then the installed binary
     */
    public static function load(?string $path = null): self
    {
        $path ??= self::resolvePath();

        try {
            $ffi = FFI::cdef(self::HEADER, $path);
        } catch (\FFI\Exception $e) {
            throw new Exception(sprintf(
                "Could not load the sasso library from %s: %s\n"
                . 'Check that the file matches this PHP process (%s, %d-bit).',
                $path,
                $e->getMessage(),
                php_uname('m'),
                PHP_INT_SIZE * 8,
            ), 0, $e);
        }

        return new self($ffi, $path);
    }

    /** Replace the process-wide handle (used by tests and by custom builds). */
    public static function setDefault(?self $library): void
    {
        self::$default = $library;
    }

    /**
     * The version the loaded library reports.
     *
     * This is sasso's internal compiler version, which tracks the release tag
     * loosely relative to the release tag. For the release these bindings
     * target, use Platform::VERSION instead.
     */
    public function version(): string
    {
        // PHP marshals a `const char *` return into a PHP string already, so
        // there is nothing left for FFI::string() to do here.
        return $this->ffi->sasso_version();
    }

    /** Absolute path of the loaded shared object. */
    public function path(): string
    {
        return $this->path;
    }

    /** The raw FFI handle, for calls this wrapper does not model. */
    public function ffi(): FFI
    {
        return $this->ffi;
    }

    private static function resolvePath(): string
    {
        $explicit = getenv('SASSO_LIBRARY');
        if (is_string($explicit) && $explicit !== '') {
            if (!is_file($explicit)) {
                throw new Exception(sprintf('SASSO_LIBRARY points at %s, which does not exist.', $explicit));
            }

            return $explicit;
        }

        $target = Platform::target();
        $path = Platform::libraryPath($target);

        if (is_file($path)) {
            return $path;
        }

        // The plugin is the normal install path; reaching here means it did not
        // run (--no-plugins, a vendor/ copied between platforms, a PHAR), so
        // fetch on demand rather than failing on something we can fix.
        if (getenv('SASSO_NO_DOWNLOAD') === '1') {
            throw new DownloadException(sprintf(
                'The sasso library for %s is not installed at %s and SASSO_NO_DOWNLOAD=1 forbids fetching it. '
                . 'Run `composer sasso:install` or set SASSO_LIBRARY.',
                $target,
                $path,
            ));
        }

        return (new Downloader())->install($target);
    }
}
