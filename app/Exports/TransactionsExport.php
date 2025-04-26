<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use App\Models\Transaction;

class TransactionsExport implements FromCollection, WithHeadings
{
    /**
     * @var Collection<int, Transaction>
     */
    protected Collection $data;

    /**
     * @param Collection<int, Transaction> $data
     */
    public function __construct(Collection $data)
    {
        $this->data = $data;
    }

    /**
     * @return Collection<int, array{
     *     Material: mixed,
     *     Course: mixed,
     *     Topic: mixed,
     *     Subtopic: mixed,
     *     Total: mixed
     * }>
     */
    public function collection(): Collection
    {
        return $this->data->map(function ($item) {
            return [
                'Material' => $item['material'],
                'Course' => $item['course'],
                'Topic' => $item['topic'],
                'Subtopic' => $item['subtopic'],
                'Total' => $item['total'],
            ];
        });
    }

    /**
     * @return array<string>
     */
    public function headings(): array
    {
        return ['Material', 'Course', 'Topic', 'Subtopic', 'Total'];
    }
}
