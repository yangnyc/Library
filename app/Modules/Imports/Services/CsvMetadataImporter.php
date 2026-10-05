<?php

namespace App\Modules\Imports\Services;

use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Models\Work;
use App\Modules\Catalog\Services\CatalogWriter;
use App\Modules\Imports\ImportRejected;
use App\Modules\Localization\Models\Language;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Bulk metadata import. Creates or updates works and DRAFT editions only: a
 * CSV row can never publish anything or attach a file.
 */
class CsvMetadataImporter
{
    public const MAX_ROWS = 500;

    public const COLUMNS = [
        'work_slug', 'original_title', 'original_language', 'authors', 'first_published',
        'edition_slug', 'language', 'direction', 'title', 'subtitle', 'description', 'translators',
        'publisher', 'published_date', 'isbn', 'edition_statement', 'source_name', 'source_url',
        'attribution', 'rights_status', 'license_name', 'license_url', 'rights_holder', 'rights_notes',
        'categories', 'tags',
    ];

    private const REQUIRED = ['original_title', 'original_language', 'language', 'title'];

    public function __construct(private readonly CatalogWriter $writer) {}

    /**
     * Validates every row first. Nothing is written unless all rows are valid
     * (or $dryRun is set, in which case nothing is written at all).
     *
     * @return array{rows:int, created_works:int, created_editions:int, updated_editions:int, errors:list<array{line:int, messages:list<string>}>}
     */
    public function import(string $path, bool $dryRun = false): array
    {
        $rows = $this->read($path);
        $languages = array_keys(Language::map());
        $errors = [];

        foreach ($rows as $line => $row) {
            $validator = Validator::make($row, [
                'work_slug' => ['nullable', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:160'],
                'original_title' => ['required', 'string', 'max:255'],
                'original_language' => ['required', 'in:'.implode(',', $languages)],
                'authors' => ['nullable', 'string', 'max:1000'],
                'first_published' => ['nullable', 'string', 'max:20'],
                'edition_slug' => ['nullable', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:190'],
                'language' => ['required', 'in:'.implode(',', $languages)],
                'direction' => ['nullable', 'in:ltr,rtl,auto'],
                'title' => ['required', 'string', 'max:255'],
                'subtitle' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:10000'],
                'translators' => ['nullable', 'string', 'max:1000'],
                'publisher' => ['nullable', 'string', 'max:255'],
                'published_date' => ['nullable', 'string', 'max:20'],
                'isbn' => ['nullable', 'string', 'max:32'],
                'edition_statement' => ['nullable', 'string', 'max:255'],
                'source_name' => ['nullable', 'string', 'max:255'],
                // Recorded as text only. The application never fetches this URL.
                'source_url' => ['nullable', 'url:http,https', 'max:2048'],
                'attribution' => ['nullable', 'string', 'max:5000'],
                'rights_status' => ['nullable', 'in:'.implode(',', Edition::RIGHTS)],
                'license_name' => ['nullable', 'string', 'max:255'],
                'license_url' => ['nullable', 'url:http,https', 'max:2048'],
                'rights_holder' => ['nullable', 'string', 'max:255'],
                'rights_notes' => ['nullable', 'string', 'max:5000'],
                'categories' => ['nullable', 'string', 'max:1000'],
                'tags' => ['nullable', 'string', 'max:1000'],
            ]);

            if ($validator->fails()) {
                $errors[] = ['line' => $line, 'messages' => $validator->errors()->all()];
            }
        }

        $summary = ['rows' => count($rows), 'created_works' => 0, 'created_editions' => 0, 'updated_editions' => 0, 'errors' => $errors];
        if ($errors !== [] || $dryRun) {
            return $summary;
        }

        DB::transaction(function () use ($rows, &$summary) {
            foreach ($rows as $row) {
                $this->importRow($row, $summary);
            }
        });

        return $summary;
    }

    private function importRow(array $row, array &$summary): void
    {
        $work = $row['work_slug'] ? Work::query()->where('slug', $row['work_slug'])->first() : null;
        $work ??= Work::query()->where('original_title', $row['original_title'])
            ->where('original_language_tag', $row['original_language'])->first();

        if (! $work) {
            $work = Work::create([
                'slug' => $row['work_slug'] ?: $this->writer->uniqueSlug(Work::class, $row['original_title']),
                'original_title' => $row['original_title'],
                'original_language_tag' => $row['original_language'],
                'first_published' => $row['first_published'] ?: null,
            ]);
            $summary['created_works']++;
        }

        if ($row['authors']) {
            $this->writer->syncContributors($work, 'author', $this->contributorIds($row['authors']));
        }
        if ($row['categories']) {
            $this->writer->syncCategories($work, $this->split($row['categories']));
        }
        if ($row['tags']) {
            $this->writer->syncTags($work, $this->split($row['tags']));
        }

        $edition = $row['edition_slug'] ? Edition::query()->where('slug', $row['edition_slug'])->first() : null;
        $fields = array_filter([
            'language_tag' => $row['language'],
            'direction' => $row['direction'] ?: Language::directionFor($row['language']),
            'title' => $row['title'],
            'subtitle' => $row['subtitle'],
            'description' => $row['description'],
            'publisher' => $row['publisher'],
            'published_date' => $row['published_date'],
            'isbn' => $row['isbn'],
            'edition_statement' => $row['edition_statement'],
            'source_name' => $row['source_name'],
            'source_url' => $row['source_url'],
            'attribution' => $row['attribution'],
            'rights_status' => $row['rights_status'],
            'license_name' => $row['license_name'],
            'license_url' => $row['license_url'],
            'rights_holder' => $row['rights_holder'],
            'rights_notes' => $row['rights_notes'],
        ], fn ($value) => $value !== null && $value !== '');

        if ($edition) {
            // Status is never touched: published editions stay published,
            // drafts stay drafts.
            $edition->update($fields);
            $summary['updated_editions']++;
        } else {
            $edition = $work->editions()->create($fields + [
                'slug' => $row['edition_slug'] ?: $this->writer->uniqueSlug(Edition::class, $row['title'].' '.$row['language']),
                'status' => 'draft',
            ]);
            $summary['created_editions']++;
        }

        if ($row['translators']) {
            $this->writer->syncContributors($edition, 'translator', $this->contributorIds($row['translators']));
        }

        // The edition may belong to a different work than the row names (it is
        // matched by slug), so it is re-indexed explicitly as well.
        $this->writer->reindex($work);
        $this->writer->reindex($edition);
    }

    /** @return array<int, array<string, string>> keyed by line number in the file */
    private function read(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new ImportRejected('The CSV file could not be opened.');
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (! $header) {
                throw new ImportRejected('The CSV file is empty.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

            if ($unknown = array_diff($header, self::COLUMNS)) {
                throw new ImportRejected('Unknown column(s): '.implode(', ', $unknown));
            }
            if ($missing = array_diff(self::REQUIRED, $header)) {
                throw new ImportRejected('Missing required column(s): '.implode(', ', $missing));
            }

            $rows = [];
            $line = 1;
            while (($data = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;
                if ($data === [null] || trim(implode('', $data)) === '') {
                    continue;
                }
                if (count($rows) >= self::MAX_ROWS) {
                    throw new ImportRejected('A CSV import is limited to '.self::MAX_ROWS.' rows. Split the file.');
                }
                if (count($data) !== count($header)) {
                    throw new ImportRejected("Line $line has ".count($data).' fields; the header has '.count($header).'.');
                }
                $row = array_combine($header, array_map(fn ($v) => trim((string) $v), $data));
                if (! mb_check_encoding(implode('', $row), 'UTF-8')) {
                    throw new ImportRejected("Line $line is not valid UTF-8. Save the file as UTF-8.");
                }
                $rows[$line] = $row + array_fill_keys(self::COLUMNS, '');
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @return list<string> */
    private function split(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $value))));
    }

    /** @return list<int> */
    private function contributorIds(string $value): array
    {
        return array_map(fn (string $name) => $this->writer->contributor($name)->id, $this->split($value));
    }
}
