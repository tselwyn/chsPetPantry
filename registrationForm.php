<!-- imports -->
<script src="https://nosir.github.io/cleave.js/dist/cleave.min.js"></script>
<script src="https://nosir.github.io/cleave.js/dist/cleave-phone.i18n.js"></script>
<!-- Hero Section with Title -->
<header class="hero-header"> 
    <div class="center-header">
        <h1>Pet Pantry Registration</h1>
    </div>
</header>

<main>
  <div class="main-content-box">
    <form class="signup-form" method="post">
	<div class="text-center spacing-bottom">
          <h2 class="mb-8">Registration Form</h2>
            <div class="info-box">
              <p class="sub-text">Please complete the following form to register a new Pet Pantry participant.</p>
              <p>An asterisk ( <em>*</em> ) indicates a required field.</p>
            </div>
	</div>
        
        <fieldset class="section-box mb-4">

            <h3 class="mt-2">Participant Information</h3>
            <p class="mb-2">
            Please enter the participant's personal and address information.
            </p>

            <div class="blue-div"></div>

            <label for="first_name">
            <em>* </em>First Name
            </label>
            <input
            type="text"
            id="first_name"
            name="first_name"
            required
            placeholder="Enter first name"
        >

        <label for="last_name">
        <em>* </em>Last Name
        </label>
        <input
        type="text"
        id="last_name"
        name="last_name"
        required
        placeholder="Enter last name"
        >

        <label for="street_address">
        <em>* </em>Street Address
        </label>
        <input
        type="text"
        id="street_address"
        name="street_address"
        required
        placeholder="Enter street address"
        >

        <label for="city">City</label>
        <input
        type="text"
        id="city"
        name="city"
        placeholder="Enter city"
        >

        <label for="state">State</label>
        <select id="state" name="state">
        <option value="VA" selected>Virginia</option>
        <option value="MD">Maryland</option>
        <option value="WV">West Virginia</option>
        <option value="DC">District of Columbia</option>
        <option value="NC">North Carolina</option>
        <option value="other">Other</option>
        </select>

        <label for="zip">ZIP Code</label>
        <input
            type="text"
            id="zip"
            name="zip"
            pattern="[0-9]{5}"
            maxlength="5"
            inputmode="numeric"
            title="Please enter a 5-digit ZIP code"
            placeholder="Enter ZIP code"
        >

    </fieldset>

        <fieldset class="section-box mb-4">
            <h3>Contact Information</h3>
            <p class="mb-2">Please enter the participant's contact information.</p>
	    <div class="blue-div"></div>

        <label for ="phone1">
            <em>* </em>Phone Number
        </label>
        <input
            type="tel"
            id="phone1"
            name="phone1"
            required
            pattern="(\D{0,1})\d{3}(\D{0,2})\d{3}(.{0,1})\d{4}"
            placeholder="Ex. (555) 555-5555"
            title="Please enter the phone number as 555-555-5555"   
        >
        <label for="email">
            <em>* </em>E-mail
        </label>
        <input
            type="email"
            id="email"
            name="email"
            required
            placeholder="Enter e-mail address"
        >
        <label for="preferred_language">
            <em>* </em>Preferred Language
        </label>
        <select id="preferred_language" name="preferred_language" required>
            <option value="English" selected>English</option>
            <option value="Spanish">Spanish</option>
            <option value="Other">Other</option>
        </select>
        </fieldset>

            <!--<label><em>* </em>Phone Type</label>
            <div class="radio-group">
	      <div class="radio-element">
                <input type="radio" id="phone-type-cellphone" name="phone_type" value="cellphone" required><label for="phone-type-cellphone">Cell</label>
	      </div>
	      <div class="radio-element">
                <input type="radio" id="phone-type-home" name="phone_type" value="home" required><label for="phone-type-home">Home</label>
	      </div>
	      <div class="radio-element">
                <input type="radio" id="phone-type-work" name="phone_type" value="work" required><label for="phone-type-work">Work</label>
	      </div>
            </div>-->

        <!--<fieldset class="section-box mb-4">
            <h3>Emergency Contact</h3>
            <p class="mb-2">Please provide us with someone to contact on your behalf in case of an emergency.</p>
	    <div class="blue-div"></div>

            <label for="emergency_contact_first_name" required><em>* </em>Contact First Name</label>
            <input type="text" id="emergency_contact_first_name" name="emergency_contact_first_name" required placeholder="Enter emergency contact first name">

            <label for="emergency_contact_last_name" required><em>* </em>Contact Last Name</label>
            <input type="text" id="emergency_contact_last_name" name="emergency_contact_last_name" required placeholder="Enter emergency contact last name">

            <label for="emergency_contact_relation"><em>* </em>Contact Relation to You</label>
            <input type="text" id="emergency_contact_relation" name="emergency_contact_relation" required placeholder="Ex. Spouse, Mother, Father, Sister, Brother, Friend">

            <label for="emergency_contact_phone"><em>* </em>Contact Phone Number</label>
            <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" pattern="\([0-9]{3}\) [0-9]{3}-[0-9]{4}" required placeholder="Enter emergency contact phone number. Ex. (555) 555-5555">

            <label><em>* </em>Contact Phone Type</label>
            <div class="radio-group">
	      <div class="radio-element">
                <input type="radio" id="phone-type-cellphone" name="emergency_contact_phone_type" value="cellphone" required><label for="phone-type-cellphone">Cell</label>
	      </div>
	      <div class="radio-element">
                <input type="radio" id="phone-type-home" name="emergency_contact_phone_type" value="home" required><label for="phone-type-home">Home</label>
	      </div>
	      <div class="radio-element">
                <input type="radio" id="phone-type-work" name="emergency_contact_phone_type" value="work" required><label for="phone-type-work">Work</label>
	      </div>
            </div>
        </fieldset>-->

        <!-- <fieldset class="section-box mb-4">
            <h3 class="mb-2">Other Required Information</h3>
	    <div class="blue-div"></div>

           <label><em>* </em>Are you volunteering for court-ordered community service?</label>
            <div class="radio-group">
	      <div class="radio-element">
                <input type="radio" id="yes" name="is_community_service_volunteer" value="yes" required>
                <label for="yes">Yes</label>
	      </div>

	      <div class="radio-element">
                <input type="radio" id="no" name="is_community_service_volunteer" value="no">
                <label for="no">No</label>
	      </div>
            </div>
         
            <label>Are there any specific skills you have that you believe could be useful for volunteering at the FredSPCA</label>
            <input type="text" id="skills" name="skills" placeholder="">

            <label>Any interests/hobbies?</label>
            <input type="text" id="interests" name="interests" placeholder="">


        </fieldset> -->

        
               

                
        <script>
            

            
           

            
            

             // Event listeners for changes in volunteer/participant selection and the complete statuses
            //document.querySelectorAll('input[name="is_community_service_volunteer"]').forEach(radio => {
              //  radio.addEventListener('change', toggleTrainingSection);
            //});



            
            // Initial check on page load
            
        </script>
        <script>
        // Initialize Cleave.js for primary phone number
        new Cleave('#phone1', {
            phone: true,
            phoneRegionCode: 'US',
            delimiter: '-',
            numericOnly: true,
        });
        </script>


        <fieldset class="section-box mb-4">
            <h3>Pet Pantry Information</h3>
            <p class="mb-2">Please enter the participant's Pet Pantry registration information.</p>
            <div class="blue-div"></div>
            <label for="participant_location">
                <em>* </em>Participant Location
            </label>
            <select id="participant_location" name="participant_location" required>
                <option value="" disabled selected>Select a location</option>
                <option value="Empower">Empower</option>
                <option value="Senior Center">Senior Center</option>
                <option value="Home Delivery">Home Delivery</option>
            </select>

            <label for="registration_date">
                <em>* </em>Registration Date
            </label>
            <input 
            type="date" 
            id="registration_date" 
            name="registration_date"
            value="<?php echo date('Y-m-d'); ?>"
            required>


            <label for="participant_status">
                <em>* </em>Participant Status
            </label>
            <select id="participant_status" name="participant_status" required>
                <option value="Active" selected>Active</option>
                <option value="Inactive">Inactive</option>
                <option value="Expired">Expired</option>
                <option value="No Service">No Service</option>
            </select>

            <label for="participant_alert">
                Alert / Flag
            </label>
            <select id="participant_alert" name="participant_alert">
                <option value="No" selected>No</option>
                <option value="Yes">Yes</option>
            </select>

            <label for="notes">
                Notes
            </label>
            <textarea id="notes" name="notes" rows="4" placeholder="Enter any additional notes about the participant..."></textarea>
        </fieldset>

              <!-- Required by backend -->
        <!--<input type="hidden" name="is_new_volunteer" value="1">
        <input type="hidden" name="total_hours_volunteered" value="0"> -->
        
        <fieldset class="section-box mb-4">
             <h3>Participant Agreement</h3>
                <p class="mb-2">
                Please confirm that the participant agrees to the Pet Pantry program rules
                and the collection of their registration information.
                </p>

            <div class="blue-div"></div>

            <label>
            <em>* </em>Participant Consent
            </label>

            <div>
            <input
            type="radio"
            id="consent_yes"
            name="participant_consent"
            value="Yes"
            required
            >
            <label for="consent_yes">Participant agrees</label>
            </div>

            <div>
            <input
            type="radio"
            id="consent_no"
            name="participant_consent"
            value="No"
            >
            <label for="consent_no">Participant does not agree</label>
            </div>
        </fieldset>


        <p class="text-center notice"></p>
        <input type="submit" value="Save Participant & Add Pets">
    </form>
   </div> 
</main>
