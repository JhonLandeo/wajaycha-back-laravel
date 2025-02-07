<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class TransactionsExport implements FromCollection, WithHeadings
{

    // export
    protected $data;

    public function __construct(Collection $data)
    {
        $this->data = $data;
    }

    public function collection()
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

    public function headings(): array
    {
        return ['Material', 'Course', 'Topic', 'Subtopic', 'Total'];
    }
}
