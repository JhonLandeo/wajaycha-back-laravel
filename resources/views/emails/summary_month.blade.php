<x-mail::message>
    # 📈 Cierre de Mes: {{ $monthName }}

    Hola Jhon, aquí tienes el análisis de tu desempeño presupuestario por categoría para el mes que acaba de finalizar.

    <x-mail::table>
        | Categoría | Presupuesto | Gasto Real | Varianza | Estado |
        |:---|---:|---:|---:|:---|
        @foreach ($budgetDeviation as $item)
        | {{ $item->category }} | S/ {{ number_format($item->budgeted, 2) }} | S/ {{ number_format($item->real, 2) }} | S/ {{ number_format($item->variance, 2) }} | {{ $item->status == 'Excedido' ? '🔴' : '🟢' }} |
        @endforeach
    </x-mail::table>

    <x-mail::panel>
        **Resumen General:** Gasto Total: S/ {{ number_format($budgetDeviation->sum('real'), 2) }}
        Ahorro/Exceso: S/ {{ number_format($budgetDeviation->sum('variance'), 2) }}
    </x-mail::panel>

    <x-mail::button :url="config('app.url') . '/dashboard'">
        Ver Gráficos en Wajaycha
    </x-mail::button>

    ¡Sigue así, el control financiero es la base del éxito!

    Saludos,<br>
    Wajaycha Bot
</x-mail::message>