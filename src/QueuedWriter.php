<?php

namespace Maatwebsite\Excel;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Jobs\AppendQueryToSheet;
use Maatwebsite\Excel\Jobs\CloseSheet;
use Maatwebsite\Excel\Jobs\QueueExport;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithEvents;
use Illuminate\Contracts\Support\Arrayable;
use Maatwebsite\Excel\Events\BeforeWriting;
use Maatwebsite\Excel\Jobs\AppendDataToSheet;
use Maatwebsite\Excel\Jobs\SerializedQuery;
use Maatwebsite\Excel\Jobs\StoreQueuedExport;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Jobs\QueuedExportEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class QueuedWriter
{
    /**
     * @var Writer
     */
    protected $writer;

    /**
     * @var int
     */
    protected $chunkSize;

    /**
     * @param Writer $writer
     */
    public function __construct(Writer $writer)
    {
        $this->writer    = $writer;
        $this->chunkSize = config('excel.exports.chunk_size', 1000);
    }

    /**
     * @param object      $export
     * @param string      $filePath
     * @param string|null $disk
     * @param string|null $writerType
     *
     * @return \Illuminate\Foundation\Bus\PendingDispatch
     */
    public function store($export, string $filePath, string $disk = null, string $writerType = null)
    {
        $tempFile = $this->writer->tempFile();

        $jobs = $this->buildExportJobs($export, $tempFile, $writerType);

        if ($export instanceof WithEvents && isset($export->registerEvents()[BeforeWriting::class])) {
            $jobs->push(new QueuedExportEvents($export, $tempFile, $writerType));
        }

        $jobs->push(new StoreQueuedExport($tempFile, $filePath, $disk));

        return QueueExport::withChain($jobs->toArray())->dispatch($export, $tempFile, $writerType);
    }

    /**
     * @param object $export
     * @param string $tempFile
     * @param string $writerType
     *
     * @return Collection
     */
    private function buildExportJobs($export, string $tempFile, string $writerType)
    {
        $sheetExports = [$export];
        if ($export instanceof WithMultipleSheets) {
            $sheetExports = $export->sheets();
        }

        $jobs = new Collection;
        foreach ($sheetExports as $sheetIndex => $sheetExport) {
            if ($sheetExport instanceof FromCollection) {
                $jobs = $jobs->merge($this->exportCollection($sheetExport, $tempFile, $writerType, $sheetIndex));
            } elseif ($sheetExport instanceof FromQuery) {
                $jobs = $jobs->merge($this->exportQuery($sheetExport, $tempFile, $writerType, $sheetIndex));
            }

            $jobs->push(new CloseSheet($sheetExport, $tempFile, $writerType, $sheetIndex));
        }

        return $jobs;
    }

    /**
     * @param FromCollection $export
     * @param string         $filePath
     * @param string         $writerType
     * @param int            $sheetIndex
     *
     * @return Collection
     */
    private function exportCollection(
        FromCollection $export,
        string $filePath,
        string $writerType,
        int $sheetIndex
    ) {
        return $export
            ->collection()
            ->chunk($this->chunkSize)
            ->map(function ($rows) use ($writerType, $filePath, $sheetIndex, $export) {
                if ($rows instanceof Arrayable) {
                    $rows = $rows->toArray();
                }

                return new AppendDataToSheet(
                    $export,
                    $filePath,
                    $writerType,
                    $sheetIndex,
                    $rows
                );
            });
    }

    /**
     * @param FromCollection $export
     * @param string         $filePath
     * @param string         $writerType
     * @param int            $sheetIndex
     *
     * @return Collection
     */
    private function exportQuery(
        FromQuery $export,
        string $filePath,
        string $writerType,
        int $sheetIndex
    ) {
        $query = $export->query();

        $i          = 0;
        $page       = 1;
        $jobs       = new Collection();
        $totalCount = $query->count();

        do {
            $jobs->push(new AppendQueryToSheet(
                $export,
                $filePath,
                $writerType,
                $sheetIndex,
                new SerializedQuery($query->forPage($page, $this->chunkSize))
            ));

            $page++;
            $i += $this->chunkSize;
        } while ($i < $totalCount);

        return $jobs;
    }
}