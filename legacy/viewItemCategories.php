<?php
    session_cache_expire(30);
    session_start();

    $loggedIn = false;
    $accessLevel = 0;
    $userID = null;
    $errors = [];
    if (isset($_SESSION['_id'])) {
        $loggedIn = true;
        $accessLevel = $_SESSION['access_level'];
        $userID = $_SESSION['_id'];
    }

    if ($accessLevel < 2) {
        header('Location: index.php');
        die();
    }

    require_once('database/dbinfo.php');
    require_once('database/dbPersons.php');
    require_once('database/dbInventoryEvent.php');
    require_once('database/dbItemCategory.php');
    require_once('database/dbItemCounts.php');
    $con = connect();

?>
    
<!DOCTYPE html>
<html>
<head>
    <?php require_once('universal.inc') ?>
    <title>Manage Item Categories | CCDA</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        pageheader {
            margin-top: 3rem;
            display: flex; justify-content: center; align-items: center;
            position: sticky;
            top: 1rem;
            z-index: 6;
        }
        .title {
            text-align: center;
            height: 3.5rem;
            width:auto;
            font-size: 2rem;
            font-weight: 600;
            color: var(--secondary-accent-color);
            padding-top: .4rem;
            border-radius: 10px;
            background-color: #ffffffee;
            white-space: nowrap;
            overflow: hidden;
        }
        .report-container {
            max-width: 1100px;
            margin: 0 auto 4rem auto;
            padding: 1rem;
        }
        .report-section {
            background-color: white;
            /* border: 1px solid var(--shadow-and-border-color); */
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .report-section h1 {
            font-size: 1.5rem;
            font-weight: 500;
            margin-bottom: 1rem;
            color: var(--secondary-accent-color);
        }
        .report-section h2 {
            font-size: 1.5rem;
            font-weight: 500;
            margin-bottom: 1rem;
            color: var(--secondary-accent-color);
        }
        .report-table {
            width: 100%;
            border-collapse: collapse;
        }
        .report-table th,
        .report-table td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--shadow-and-border-color);
            color: var(--page-font-color);
        }
        .report-table th {
            background-color: var(--main-color);
            color: var(--button-font-color);
            font-weight: 500;
            position: sticky;
            top: 100px; /* height of page header */
        }
        .report-table tr:hover {
            background-color: rgba(255,255,255,0.05);
        }
        .low-stock-badge {
            display: inline-block;
            background-color: var(--error-toast-background-color);
            color: var(--error-toast-font-color);
            padding: 0.2rem 0.6rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .expired-text {
            color: var(--error-toast-background-color);
            font-weight: 600;
        }
        .chart-wrapper {
            position: relative;
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
        }
        .chart-controls {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        .chart-controls button {
            padding: 0.4rem 1rem;
            border: 2px solid var(--accent-color);
            border-radius: 0.25rem;
            background-color: transparent;
            color: var(--page-font-color);
            cursor: pointer;
            font-weight: 500;
            width: auto;
            font-size: 0.85rem;
        }
        .chart-controls button.active,
        .chart-controls button:hover {
            background-color: var(--accent-color);
            color: var(--button-font-color);
        }
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--inactive-font-color);
        }
        .basket-options {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        .basket-row {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .basket-label {
            color: var(--page-font-color);
            width: 160px;
            flex-shrink: 0;
        }
        .basket-qty {
            width: 100px;
            padding: 0.4rem 0.6rem;
            border: 1px solid var(--shadow-and-border-color);
            border-radius: 0.25rem;
            background-color: rgba(0,0,0,0.2);
            color: var(--page-font-color);
            font-size: 0.9rem;
        }
        .modify-btn {
            padding: 0.5rem 1.5rem;
            background-color: var(--accent-color);
            color: var(--button-font-color);
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            width: auto;
        }
        .modify-btn:hover {
            opacity: 0.85;
        }
        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .section-header h2 {
            margin-bottom: 0;
        }
        .add-category-lnk {
            white-space: nowrap;
        }
        .add-category-lnk button {
            padding: 0.5rem 1rem;
            background-color: var(--accent-color);
            color: var(--button-font-color);
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            margin-bottom: 0.25rem;
            text-align: center;
        }
        .add-category-lnk button:hover {
            opacity: 0.85;
        }
        @media only screen and (max-width: 768px) {
            pageheader {
                top: 100px;
            }
            .title {
                border-radius: 0;
                background-color: #ffffff;
                width: 100%;
            }
            .report-table th,
            .report-table td {
                padding: 0.5rem;
                font-size: 0.8rem;
                position: static;

            }
            .report-container {
                padding: 0.5rem;
            }
            div.table-wrapper {
                overflow-x: auto;
            }
            .report-section{
                padding: 0;
            }
        }
    </style>
</head>
<pageheader>
    <h1 class="title">Manage Item Categories</h1>
</pageheader>
<body>
    <?php require_once('header.php'); ?>
    <main>
        <div class="report-container">

            <?php if (!empty($errors)): ?>
                <ul>
                    <?php foreach($errors AS $error): ?>
                        <li><?php echo("<h4 style=\"color:red;\"><i>".$error."</i></h4>"); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php 
                require_once('database/dbItemCategory.php');
                /* display table of Item Categories with a given status (Active/Inactive/Deleted) */
                $display_accounts_by_status = function($status, $accessLevel, $extraButton = ''){
                    echo '
                    <div class="report-section">
                        <div class="section-header">
                            <h2>'.$status.' Item Categories</h2>
                            '.$extraButton.'
                        </div>
                        <div class="table-wrapper">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Banana Box</th>
                                        <th>Shopping List Only</th>
                                        <th>Items Per Box</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody> ';
                            
                                $num_active = 0;
                                $categories = get_all_ItemCategory();
                                foreach ($categories as $category) {
                                    if($category->getStatus() == $status){
                                        $num_active += 1;

                                        $name = $category->getName();
                                        $itemsPerBox = $category->getItemsPerBox();
                                        $bananaBox = $category->getBananaBox() == 1 ? "✓" : "";
                                        $shopOnly = $category->getShopOnly() == 1 ? "✓" : "";
                                        // Gray out rows for shopping list only items
                                        $rowClass = $category->getShopOnly() == 1 ? ' class="row-gray"' : '';

                                        echo '
                                        <tr' . $rowClass . '>
                                            <td>' . $name . '</td>
                                            <td style="text-align: center;">' . $bananaBox . '</td>
                                            <td style="text-align: center;">' . $shopOnly . '</td>
                                            <td>' . $itemsPerBox . '</td>';
                                        echo ' 
                                            <td><a href="viewModifyItemCategory.php?id=' . $category->getId() . '" class="text-blue-700 underline"><button class="modify-btn">Modify</button></a>
                                        </tr>';
                                    }
                                }
                                if($num_active == 0){
                                    echo '
                                    <tr>
                                        <td colspan="5" class="empty-state">No '.$status.' Categories</td>
                                    </tr>';
                                }

                    echo '
                                </tbody>
                            </table>
                        </div>
                    </div>';
                            }; ?>

                            <!-- Display Table of accounts for each Status -->
                            <?php
                            $addCategoryButton = '<a href="viewAddItemCategory.php" class="add-category-lnk"><button type="button">Add Category</button></a>';
                            $display_accounts_by_status("Active", $accessLevel, $addCategoryButton);
                            $display_accounts_by_status("Inactive", $accessLevel);
                            /* Superadmin can see deleted accounts */
                            if($accessLevel >= 3){
                                $display_accounts_by_status("Deleted", $accessLevel);
                            }
                            ?>
        </div>

    </main>

</body>
</html>
