<?php
    session_cache_expire(30);
    session_start();
    require_once(__DIR__ . '/include/sessionTimeout.php'); // 30-minute inactivity sign-out (NFR-SEC-005)
    header("refresh:2; url=viewAllEvents.php");
?>
    <!DOCTYPE html>
    <html>
        <head>
            <?php require_once('universal.inc') ?>
            <title>CCDA | Sign-Up for Event</title>
            <style>

                h1 {
                    font-family: Quicksand, sans-serif;
                    color: #white;
                    font-weight: normal;
                    font-size: 30px;
                }
            </style>
        </head>
        <body>
            <?php require_once('header.php') ?>
            <h1>Sign-Up Approved!</h1>
        </body>
    </html>