<x-mail::message>
    # 📊 Wajaycha: Resumen Diario

    Hola, aquí tienes el estado de tu presupuesto para hoy.

    <x-mail::panel>
        **Presupuesto Mensual:** S/ {{ number_format($summary->income_total_by_month, 2) }}
    </x-mail::panel>

    <x-mail::table>
        | Métrica | Monto |
        |:--------|:------|
        | Meta de gasto diario | S/ {{ number_format($summary->avg_expense_day, 2) }} |
        | Gasto acumulado del mes | S/ {{ number_format($summary->total_expense, 2) }} |
        | **Nuevo límite sugerido** | **S/ {{ number_format($summary->new_expense_day, 2) }}** |
    </x-mail::table>

    Si superas el límite sugerido de hoy, ajustaremos el presupuesto de mañana. 🚀

    <x-mail::button :url="config('app.url')">
        Ir a Wajaycha Dashboard
    </x-mail::button>

    Saludos,<br>
    Tu Asistente Financiero
</x-mail::message>