<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Beam rig — stub dashboard of user {{ auth()->id() }}</title>
    @beamAssets
</head>
<body style="font:14px system-ui;background:#111;color:#ddd;margin:24px">
    <h1>a page the user keeps open — with the copied incoming modal</h1>
    @include('rig-echo')
    @include('beam.incoming')
</body>
</html>
