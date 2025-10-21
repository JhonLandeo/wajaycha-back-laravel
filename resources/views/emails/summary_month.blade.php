<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Document</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            color: #333;
        }

        h3 {
            text-align: center;
            color: #2c3e50;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        th,
        td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        th {
            background-color: #2c3e50;
            color: white;
        }

        .bg-red-500 {
            background-color: #e74c3c !important;
            color: white !important;
        }

        .bg-green-500 {
            background-color: #2ecc71 !important;
            color: white !important;
        }
    </style>
</head>

<body>
    <h3>Desviacion presupuestaria mensual</h3>

    <table>
        <thead>
            <tr>
                <th>Categoria</th>
                <th>Presupuestado</th>
                <th>Real</th>
                <th>Varianza</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($budgetDeviation as $item)
                <tr class="{{ $item->status == 'Excedido' ? 'bg-red-500' : 'bg-green-500' }}" style="padding: 5px">
                    <td>{{ $item->category }}</td>
                    <td>S/.{{ number_format($item->budgeted, 2) }}</td>
                    <td>S/.{{ number_format($item->real, 2) }}</td>
                    <td>S/.{{ number_format($item->variance, 2) }}</td>
                    <td>{{ $item->status }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

</body>


</html>
