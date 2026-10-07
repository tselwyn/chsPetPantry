<!-- imports -->
<script src="https://nosir.github.io/cleave.js/dist/cleave.min.js"></script>
<script src="https://nosir.github.io/cleave.js/dist/cleave-phone.i18n.js"></script>
<!-- Hero Section with Title -->
<header class="hero-header"> 
    <div class="center-header">
        <h1>Pet Registration</h1>
    </div>
</header>

<main>
  <div class="main-content-box">
    <form class="signup-form" method="post">
	<div class="text-center spacing-bottom">
          <h2 class="mb-8">Pet Registration Form</h2>
            <div class="info-box">
              <p class="sub-text">Please complete the following form to register a new Pet.</p>
              <p>An asterisk ( <em>*</em> ) indicates a required field.</p>
            </div>
	</div>
        
        <fieldset class="section-box mb-4">

            <h3 class="mt-2">Pet Information</h3>
            <p class="mb-2">
            Please enter the pet's information.
            </p>

            <div class="blue-div"></div>

        <label for="pet_name">
            <em>* </em>Name
        </label>
        <input
            type="text"
            id="pet_name"
            name="pet_name"
            required
            placeholder="Enter pet's name"
        >
        
        
        <label for="animal_type">
            <em>* </em>Animal Type
        </label>
        <select id="animal_type" name="animal_type" required>
            <option value="">Select Animal Type</option>
            <option value="dog">Dog</option>
            <option value="cat">Cat</option>
            <option value="bird">Other</option>
        </select>

        <label for="breed">Breed</label>
        <input
            type="text"
            id="breed"
            name="breed"
            placeholder="Enter breed"
        >

        <label for="age">Age</label>
        <input
            type="number"
            id="age"
            name="age"
            min="0"
            placeholder="Enter age"
        >

        <label for="weight">Weight</label>
        <input
            type="number"
            id="weight"
            name="weight"
            min="0"
            step="0.1"
            placeholder="Enter weight"
        >

        <label for="sex">Sex</label>
        <select id="sex" name="sex">
            <option value="male">Male</option>
            <option value="female">Female</option>
        </select>

        <label for="Spay_Neuter">Spay/Neuter</label>
        <select id="Spay_Neuter" name="Spay_Neuter">
            <option value="yes">Yes</option>
            <option value="no">No</option>
        </select>

        <label for="food_type">Food Type</label>
        <select id="food_type" name="food_type">
            <option value="dry">Dry</option>
            <option value="wet">Wet</option>
            <option value="either">Either</option>
        </select>

        <label for="food_restriction">Food Restrictions</label>
        <textarea id="food_restriction" name="food_restriction" placeholder="Enter any food restrictions"></textarea>

        <label for="notes">Notes</label>
        <textarea id="notes" name="notes" placeholder="Enter any additional notes"></textarea>

        </fieldset>

        <p class="text-center notice"></p>
        <input type="submit" value="Save Pet">
    </form>
   </div> 
</main>
