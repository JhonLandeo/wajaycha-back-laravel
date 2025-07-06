<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            color: #333;
        }

        h2 {
            text-align: center;
            color: #2c3e50;
        }

        ul {
            list-style-type: none;
            padding: 0;
        }

        li {
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }

        li:last-child {
            border-bottom: none;
        }
    </style>
</head>

<body>
    <div>
        <h2>Resumen de Transacciones por Dia</h2>
        <ul>
            <li>Presupuesto a gastar por mes: S/.{{ $summary->income_total_by_month }}</li>
            <li>Cuanto deberia gastar diario: S/.{{ $summary->avg_expense_day }}</li>
            <li>Cuanto se gasto hasta la actualidad correspondiente al mes: S/.{{ $summary->total_expense }}</li>
            <li>Monto actualizado a gastar por dia S/.{{ $summary->new_expense_day }}</li>
        </ul>
    </div>
</body>

</html>
