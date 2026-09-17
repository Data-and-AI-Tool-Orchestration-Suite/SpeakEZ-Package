<?php
/** @var User $user */
$page = 'unauthorized';
global $rootURL;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $rootURL?>/img/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $rootURL?>/img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $rootURL?>/img/favicon-16x16.png">
    <link rel="manifest" href="<?= $rootURL?>/img/site.webmanifest">
    <title>Unauthorized</title>
</head>
<body>
    <div class="container">
        <h1>Sorry folks, your account is unauthorized.</h1>
        <p>The moose out front shoulda told ya.</p>
        <small><a href="mailto:ai@uky.edu">Contact us</a> if you believe this is an error.</small>
    </div>

    <style>
        @import url('https://fonts.googleapis.com/css?family=Chicle');
        html, body {
            margin: 0;
            height: 100vh;
            font-family: 'Chicle', cursive;
        }
        body {
            background: url('<?= $rootURL ?>/img/lampoon.jpg');
            background-size: cover;
            background-position: 50% 50%;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .container {
            background: rgba(255,255,255,0.8);
            padding: 1em 2em;
            font-size: 1.5em;
            text-align: center;
            margin-top: 30vh;
        }
        h1 {
            color: #e60000;
        }

    </style>

</body>