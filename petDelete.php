<?php
    // Delete a pet from the pets table on participantEdit.php. Volunteers and
    // admins can both delete pets. The row is kept with status Deleted, like
    // deleteParticipantPet.php does, so distribution history still adds up.
    session_cache_expire(30);
    session_start();

    $accessLevel = isset($_SESSION['_id']) ? $_SESSION['access_level'] : 0;
    if ($accessLevel < 1) {
        header('Location: index.php');
        die();
    }

    require_once('database/dbPets.php');

    // Only a submitted form deletes; following a link does nothing
    $id = (isset($_POST['pet_id']) && is_string($_POST['pet_id']) && ctype_digit($_POST['pet_id'])) ? (int) $_POST['pet_id'] : 0;
    $pet = ($_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0) ? get_pet_by_id($id) : null;
    if ($pet === null) {
        header('Location: participantSearch.php');
        die();
    }

    $result = ($pet->get_status() === 'Deleted' || delete_pet($pet->get_id())) ? 'petDeleted' : 'petNotDeleted';
    header('Location: participantEdit.php?id=' . (int) $pet->get_participant_id() . '&' . $result . '#pets');
    die();
