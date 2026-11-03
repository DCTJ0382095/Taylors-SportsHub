<?php
require __DIR__ . '/../includes/db.php';
db(); // throws an error if the database connection fails
echo "Taylor's SportsHub is running and connected to the database.";