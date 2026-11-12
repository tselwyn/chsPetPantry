<?php
/*
 * The pet fields shared by registerPet.php and petEdit.php. The including
 * page requires include/pet-form.php and sets $args to the values to show,
 * keyed by field name.
 */
?>
<style>
/* Scoped styles: shared by registerPet.php and petEdit.php */
.pet-fields-redesign { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px 20px; }
.pet-fields-redesign .pet-field { min-width:0; }
.pet-fields-redesign .pet-field label { display:block; margin:0 0 5px; font-weight:600; }
.pet-fields-redesign .pet-field input,
.pet-fields-redesign .pet-field select,
.pet-fields-redesign .pet-field textarea { box-sizing:border-box; width:100%; margin:0; }
.pet-fields-redesign .pet-field textarea { min-height:74px; }
.pet-fields-redesign .pet-field-full { grid-column:1 / -1; }
.pet-fields-redesign .pet-section-heading { grid-column:1 / -1; margin:12px 0 0; padding-bottom:7px; border-bottom:1px solid #57c5d9; color:#205968; font-weight:700; }
.pet-fields-redesign .pet-field p { font-size:.85rem; margin:5px 0 0; }
@media(max-width:680px) { .pet-fields-redesign { grid-template-columns:1fr; } }
</style>
<div class="pet-fields-redesign">
<div class="pet-section-heading">Basic Pet Information</div>
<div class="pet-field">
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
</div>
<div class="pet-field">
<label for="species">
            <em>* </em>Species
        </label>
        <select id="species" name="species" required>
            <?php pet_options(array('' => 'Select Species', 'Dog' => 'Dog', 'Cat' => 'Cat', 'Other' => 'Other'), $args['species']) ?>
        </select>
</div>
<div class="pet-field">
<label for="breed">Breed</label>
        <input
            type="text"
            id="breed"
            name="breed"
            placeholder="Enter breed"
            value="<?php echo hsc($args['breed']) ?>"
        >
</div>
<div class="pet-field">
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
</div>
<div class="pet-field">
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
</div>
<div class="pet-field">
<label for="sex">Sex</label>
        <select id="sex" name="sex">
            <?php pet_options(array('Unknown' => 'Unknown', 'Male' => 'Male', 'Female' => 'Female'), $args['sex']) ?>
        </select>
</div>
<div class="pet-section-heading">Health &amp; Care</div>
<div class="pet-field">
<label for="spay_neuter">Spay/Neuter Status</label>
        <select id="spay_neuter" name="spay_neuter" required>
            <?php pet_options(array('Unknown' => 'Unknown', 'Yes' => 'Yes', 'No' => 'No'), $args['spay_neuter']) ?>
        </select>
</div>
<div class="pet-field">
<label for="rabies_status">Rabies Vaccination Status</label>
        <select id="rabies_status" name="rabies_status" required>
            <?php pet_options(array('Unknown' => 'Unknown', 'Yes' => 'Yes', 'No' => 'No'), $args['rabies_status']) ?>
        </select>
</div>
<div class="pet-field pet-field-full">
<label for="special_medical_needs">Special Medical Needs</label>
        <textarea id="special_medical_needs" name="special_medical_needs" maxlength="<?php echo PET_MAX_MEDICAL_NEEDS ?>" placeholder="Enter any medications, conditions, or care the pet needs"><?php echo hsc($args['special_medical_needs']) ?></textarea>
</div>
<div class="pet-section-heading">Food Preferences</div>
<div class="pet-field">
<label for="food_type">Food Type</label>
        <select id="food_type" name="food_type">
            <?php pet_options(array('dry' => 'Dry', 'wet' => 'Wet', 'either' => 'Either', '' => 'Not specified'), $args['food_type']) ?>
        </select>
</div>
<div class="pet-field pet-field-full">
<label for="food_restriction">Food Restrictions</label>
        <textarea id="food_restriction" name="food_restriction" placeholder="Enter any food restrictions"><?php echo hsc($args['food_restriction']) ?></textarea>
</div>
<?php if (array_key_exists('pet_status', $args)): ?>
<div class="pet-section-heading">Pet Status</div>
<div class="pet-field pet-field-full">
<label for="pet_status">
            <em>* </em>Pet Status
        </label>
        <select id="pet_status" name="pet_status" required aria-describedby="pet_status_help">
            <?php pet_options(pet_status_choices($args['pet_status_current']), $args['pet_status']) ?>
        </select>
        <p id="pet_status_help" class="mb-2">If the pet no longer lives with this participant, choose Rehomed or Deceased instead of deleting it, so its history is kept.</p>
</div>
<?php endif ?>
<div class="pet-section-heading">Additional Notes</div>
<div class="pet-field pet-field-full">
<label for="notes">Notes</label>
        <textarea id="notes" name="notes" placeholder="Enter any additional notes"><?php echo hsc($args['notes']) ?></textarea>
</div>
</div>
