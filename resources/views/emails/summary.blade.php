<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>

<body>
    <div>
        <h2>Resumen de Transacciones por Dia</h2>
        <ul>
            <li>Presupuesto a gastar por mes: {{ $summary->income_total_by_month }}</li>
            <li>Cuanto deberia gastar diario: {{ $summary->avg_expense_day }}</li>
            <li>Cuanto se gasto hasta la actualidad correspondiente al mes: {{ $summary->total_expense }}</li>
            <li>Monto actualizado a gastar por dia {{ $summary->new_expense_day }}</li>
        </ul>
    </div>
</body>

</html>
