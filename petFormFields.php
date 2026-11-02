<?php
/*
 * The pet fields shared by registerPet.php and petEdit.php. The including
 * page requires include/pet-form.php and sets $args to the values to show,
 * keyed by field name.
 */
?>
        <label for="name">
            <em>* </em>Name
        </label>
        <input
            type="text"
            id="name"
            name="name"
            required
            placeholder="Enter pet's name"
            value="<?php echo hsc($args['name']) ?>"
        >

        <label for="species">
            <em>* </em>Species
        </label>
        <select id="species" name="species" required>
            <?php pet_options(array('' => 'Select Species', 'Dog' => 'Dog', 'Cat' => 'Cat', 'Other' => 'Other'), $args['species']) ?>
        </select>

        <label for="breed">Breed</label>
        <input
            type="text"
            id="breed"
            name="breed"
            placeholder="Enter breed"
            value="<?php echo hsc($args['breed']) ?>"
        >

        <label for="age_years">
            <em>* </em>Age (Years)
        </label>
        <input
            type="number"
            id="age_years"
            name="age_years"
            required
            min="<?php echo PET_MIN_AGE_YEARS ?>"
            max="<?php echo PET_MAX_AGE_YEARS ?>"
            step="0.1"
            inputmode="decimal"
            placeholder="Ex. 3, or 0.5 for six months"
            value="<?php echo hsc($args['age_years']) ?>"
        >

        <label for="weight_lbs">Weight(lbs)</label>
        <input
            type="number"
            id="weight_lbs"
            name="weight_lbs"
            min="0"
            step="0.1"
            placeholder="Enter weight"
            value="<?php echo hsc($args['weight_lbs']) ?>"
        >

        <label for="sex">Sex</label>
        <select id="sex" name="sex">
            <?php pet_options(array('Unknown' => 'Unknown', 'Male' => 'Male', 'Female' => 'Female'), $args['sex']) ?>
        </select>

        <label for="spay_neuter">Spay/Neuter Status</label>
        <select id="spay_neuter" name="spay_neuter" required>
            <?php pet_options(array('Unknown' => 'Unknown', 'Yes' => 'Yes', 'No' => 'No'), $args['spay_neuter']) ?>
        </select>

        <label for="rabies_status">Rabies Vaccination Status</label>
        <select id="rabies_status" name="rabies_status" required>
            <?php pet_options(array('Unknown' => 'Unknown', 'Yes' => 'Yes', 'No' => 'No'), $args['rabies_status']) ?>
        </select>

        <label for="food_type">Food Type</label>
        <select id="food_type" name="food_type">
            <?php pet_options(array('dry' => 'Dry', 'wet' => 'Wet', 'either' => 'Either', '' => 'Not specified'), $args['food_type']) ?>
        </select>

        <label for="food_restriction">Food Restrictions</label>
        <textarea id="food_restriction" name="food_restriction" placeholder="Enter any food restrictions"><?php echo hsc($args['food_restriction']) ?></textarea>

        <label for="notes">Notes</label>
        <textarea id="notes" name="notes" placeholder="Enter any additional notes"><?php echo hsc($args['notes']) ?></textarea>
