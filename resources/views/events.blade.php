<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Events</title>

    <!-- CSS -->
    <!-- BOOTSTRAP -->
    <link rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.1.1/css/bootstrap.min.css">
    <style>
		table {
			font-family: arial, sans-serif;
			border-collapse: collapse;
			width: 100%;
		}

		td, th {
			border: 1px solid #dddddd;
			text-align: left;
			padding: 4px;
		}

		tr:nth-child(even) {
			background-color: #dddddd;
		}
    </style>
</head>
<body class="container">
<div class="col-sm-8 col-sm-offset-2">

	<table>
	<tr>
		<th>ID</th>
		<th>SRC_IP</th>
		<th>NAME</th>
		<th>TYPE</th>
		<th>RESOLVED</th>
		<th>TITLE</th>
		<th>PROCESSED</th>
		<th>CREATED_AT</th>
		<th>UPDATED_AT</th>
		<th>DELETED_AT</th>
	</tr>
    @foreach ($events as $event)
		<tr>
			<td>{{ $event->id }}</td>
			<td>{{ $event->src_ip }}</td>
			<td>{{ $event->name }}</td>
			<td>{{ $event->type }}</td>
			<td>{{ $event->resolved }}</td>
			<td>{{ $event->title }}</td>
			<td>{{ $event->processed }}</td>
			<td>{{ $event->created_at }}</td>
			<td>{{ $event->updated_at }}</td>
			<td>{{ $event->deleted_at }}</td>
		</tr>
    @endforeach
	</table>
</div>
</body>
</html>