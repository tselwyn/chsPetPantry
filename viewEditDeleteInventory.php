<?php
    session_cache_expire(30);
    session_start();

    $loggedIn = false;
    $accessLevel = 0;
    $userID = null;
    if (isset($_SESSION['_id'])) {
        $loggedIn = true;
        $accessLevel = $_SESSION['access_level'];
        $userID = $_SESSION['_id'];
    }

    /* Access control - Managers only */
    if($accessLevel < 2) {
        header('Location: index.php');
        die();
    }

    require_once('database/dbinfo.php');
    require_once('database/dbInventoryEvent.php');
    require_once('database/dbItemCounts.php');

    /* Get sort order from dropdown (default: newest-oldest) */
    $sortOrder = $_GET['sort'] ?? 'newest-oldest';

    /* Get all inventory events */
    $allEventObjects = get_all_inventoryEvents();

    /* Sort based on selected order */
    if($sortOrder == 'oldest-newest') {
        /* Sort by date (oldest first), then by ID (lowest first) */
        usort($allEventObjects, function($a, $b) {
            $dateDiff = strtotime($a->getDate()) - strtotime($b->getDate());
            if ($dateDiff != 0) {
                return $dateDiff;
            }
            return $a->getId() - $b->getId();
        });
    } else {
        /* Sort by date (newest first), then by ID (highest first) - DEFAULT */
        usort($allEventObjects, function($a, $b) {
            $dateDiff = strtotime($b->getDate()) - strtotime($a->getDate());
            if ($dateDiff != 0) {
                return $dateDiff;
            }
            return $b->getId() - $a->getId();
        });
    }

    /* Group events into pairs (warehouse + matching pantry) */
    $eventPairs = array();
    foreach($allEventObjects as $event) {
        /* Only process warehouse events (pantry will be paired automatically) */
        if($event->getLocation() == 'Warehouse') {
            /* Find matching pantry event using the pairing function */
            $pantryEvent = get_matching_inventoryEvent($event);

            /* Check if warehouse has any non-zero items */
            $warehouseHasData = false;
            $warehouseCounts = get_itemCounts_by_inventoryEvent($event->getId());
            foreach($warehouseCounts as $count) {
                if($count->getQuantity() > 0) {
                    $warehouseHasData = true;
                    break;
                }
            }

            /* Check if pantry has any non-zero items */
            $pantryHasData = false;
            if($pantryEvent) {
                $pantryCounts = get_itemCounts_by_inventoryEvent($pantryEvent->getId());
                foreach($pantryCounts as $count) {
                    if($count->getQuantity() > 0) {
                        $pantryHasData = true;
                        break;
                    }
                }
            }

            $eventPairs[] = array(
                'warehouse' => $event,
                'pantry' => $pantryEvent,
                'date' => $event->getDate(),
                'warehouseHasData' => $warehouseHasData,
                'pantryHasData' => $pantryHasData
            );
        }
    }

    /* Also process Pantry events with no matching warehouse */
    foreach($allEventObjects as $event) {
        if($event->getLocation() == 'Pantry') {
            $warehouseEvent = get_matching_inventoryEvent($event);

            /* Only add if no matching warehouse exists */
            if($warehouseEvent === null) {
                /* Check if pantry has any non-zero items */
                $pantryHasData = false;
                $pantryCounts = get_itemCounts_by_inventoryEvent($event->getId());
                foreach($pantryCounts as $count) {
                    if($count->getQuantity() > 0) {
                        $pantryHasData = true;
                        break;
                    }
                }

                $eventPairs[] = array(
                    'warehouse' => null,
                    'pantry' => $event,
                    'date' => $event->getDate(),
                    'warehouseHasData' => false,
                    'pantryHasData' => $pantryHasData
                );
            }
        }
    }

    /* Re-sort eventPairs by date after adding orphan pantry events */
    if($sortOrder == 'oldest-newest') {
        usort($eventPairs, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });
    } else {
        usort($eventPairs, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
    }
?>

<!DOCTYPE html>
<html>
<head>
    <?php require_once('universal.inc') ?>
    <title>Edit/Delete Inventory | Whiskey Valor Foundation</title>
    <style>
        .main-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 1rem 2rem;
        }
        pageheader {
            margin-top: 3rem;
            display: flex; justify-content: center; align-items: center;
        }
        .title {
            position: fixed;
            text-align: center;
            height: 3.5rem;
            width: 40%;
            z-index: 1000;
            font-size: 2rem;
            font-weight: 600;
            color: var(--secondary-accent-color);
            background-color: white;
            padding-top: 0;
            mask-image: linear-gradient(to right, transparent, black 20%, black 80%, transparent);
        }
        .inventory-table {
            width: 100%;
            border-collapse: collapse;
            background-color: white;
            border-radius: 10px;
            overflow: hidden;
        }
        .inventory-table th {
            background-color: var(--main-color);
            color: var(--button-font-color);
            padding: 1rem;
            text-align: left;
            font-weight: 500;
        }
        .inventory-table th:last-child,
        .inventory-table td:last-child {
            width: 220px;
        }
        .inventory-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--shadow-and-border-color);
            color: var(--page-font-color);
        }
        .inventory-table tr:hover {
            background-color: rgba(255,255,255,0.05);
        }
        .modify-btn {
            padding: 0.6rem 1.3rem;
            background-color: var(--accent-color);
            color: var(--button-font-color);
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            font-weight: 500;
            font-size: 1rem;
            margin-right: 0.5rem;
        }
        .modify-btn:hover {
            opacity: 0.85;
        }
        .delete-btn {
            padding: 0.6rem 1.3rem;
            background-color: #dc2626;
            color: white;
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            font-weight: 500;
            font-size: 1rem;
        }
        .delete-btn:hover {
            background-color: #b91c1c;
        }
        .success-message {
            background-color: #dcfce7;
            color: #166534;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            font-weight: 500;
        }
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--inactive-font-color);
        }
        .sort-container {
            margin-bottom: 1.5rem;
        }
        .select {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--shadow-and-border-color);
            border-radius: 0.25rem;
            background-color: white !important;
            color: var(--page-font-color);
            cursor: pointer;
            width: auto;
        }
        @media only screen and (max-width: 768px) {
            .inventory-table th,
            .inventory-table td {
                padding: 0.5rem;
                font-size: 0.8rem;
            }
            .main-container {
                padding: 0.5rem;
            }
        }
    </style>
</head>
<pageheader>
    <h1 class="title">Edit/Delete Inventory</h1>
</pageheader>
<body>
    <?php require_once('header.php') ?>
    <main class="main-container">

        <!-- Success Message -->
        <?php if(isset($_GET['deleted']) && $_GET['deleted'] == 'success'): ?>
            <?php
                $deletedDate = $_GET['date'] ?? '';
                $deletedLocation = $_GET['location'] ?? '';
                $formattedDate = $deletedDate ? date("F jS, Y", strtotime($deletedDate)) : '';
            ?>
            <h4 style="color:black;"><i>Inventory Event Deleted: <?= $formattedDate ?>  -  <?= htmlspecialchars($deletedLocation) ?></i></h4>
        <?php endif; ?>

        <!-- Sort Order Dropdown -->
        <div class="sort-container">
            <form method="GET" style="margin: 0;">
                <select name="sort" id="sortOrder" class="select" onchange="this.form.submit()">
                    <option value="newest-oldest" <?= $sortOrder == 'newest-oldest' ? 'selected' : '' ?>>Newest - Oldest</option>
                    <option value="oldest-newest" <?= $sortOrder == 'oldest-newest' ? 'selected' : '' ?>>Oldest - Newest</option>
                </select>
            </form>
        </div>

        <table class="inventory-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th style="text-align: center;">Warehouse</th>
                    <th style="text-align: center;">Pantry</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(count($eventPairs) > 0): ?>
                    <?php foreach($eventPairs as $index => $pair): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= date('M j, Y', strtotime($pair['date'])) ?></td>
                            <td style="text-align: center;">
                                <?= $pair['warehouseHasData'] ? '✓' : '-' ?>
                            </td>
                            <td style="text-align: center;">
                                <?= $pair['pantryHasData'] ? '✓' : '-' ?>
                            </td>
                            <td style="white-space: nowrap;">
                                <?php if($pair['warehouse']): ?>
                                    <a href="editInventoryEvent.php?warehouseId=<?= htmlspecialchars($pair['warehouse']->getId()) ?>" style="display: inline-block;">
                                        <button class="modify-btn">Edit</button>
                                    </a>
                                    <a href="deleteInventoryEvent.php?warehouseId=<?= htmlspecialchars($pair['warehouse']->getId()) ?>" style="display: inline-block;">
                                        <button class="delete-btn">Delete</button>
                                    </a>
                                <?php else: ?>
                                    <a href="editInventoryEvent.php?pantryId=<?= htmlspecialchars($pair['pantry']->getId()) ?>" style="display: inline-block;">
                                        <button class="modify-btn">Edit</button>
                                    </a>
                                    <a href="deleteInventoryEvent.php?pantryId=<?= htmlspecialchars($pair['pantry']->getId()) ?>" style="display: inline-block;">
                                        <button class="delete-btn">Delete</button>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="empty-state">
                            No inventory records found.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </main>
</body>
</html>
