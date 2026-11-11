<?php

declare(strict_types=1);
require_once __DIR__ . '/auth_check.php';

$role = $_SESSION['role_name'] ?? '';

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Kibiri Hospital HMIS</title>

    <link
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
      rel="stylesheet">

    <link rel="stylesheet" href="assets/style.css">

</head>

<body>

<nav class="navbar navbar-dark hospital-navbar px-4">

    <span class="navbar-brand fw-bold">
        KIBIRI HOSPITAL HMIS
    </span>

    <a href="logout.php"
       class="btn btn-outline-light btn-sm">
        Logout
    </a>

</nav>

<div class="container py-5">

    <div class="mb-4">

        <h2>Welcome, <?= htmlspecialchars(
            $_SESSION['full_name'],
            ENT_QUOTES,
            'UTF-8'
        ) ?></h2>

        <p class="text-muted">
            Hospital Management Information System
        </p>

    </div>

    <div class="card shadow-sm border-0">

        <div class="card-body p-4">

            <h4>System Access Successful</h4>

            <p>
                You have successfully logged into
                Kibiri Hospital HMIS.
            </p>

            <p>
                Your assigned role:
                <strong><?= htmlspecialchars(
                    $role,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?></strong>
            </p>

            <p>
                Your department:
                <strong><?= htmlspecialchars(
                    $_SESSION['department_name'] ?? 'Unassigned',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?></strong>
            </p>

            <div class="alert alert-info mb-0">

                The main hospital modules will be introduced
                progressively during development.

            </div>

        </div>

    </div>

</div>

</body>
</html>
