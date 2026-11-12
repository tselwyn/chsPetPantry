<!-- CHS Pet Pantry participant registration form -->
<style>
.chs-registration {max-width:1050px;margin:24px auto 48px;padding:0 18px;font-family:inherit;color:#173e4a}
.chs-registration .signup-form {display:block;width:100%;max-width:none;margin:0;padding:0;background:transparent;box-shadow:none}
.chs-registration .registration-intro {margin-bottom:20px;color:#465b64}
.chs-registration .registration-grid {display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 22px}
.chs-registration .registration-field {min-width:0;display:flex;flex-direction:column;gap:6px}
.chs-registration .registration-field.wide {grid-column:1/-1}
.chs-registration .section-box {display:block;margin:0 0 20px;padding:22px 24px;border:1px solid #d3e4e8;border-radius:12px;background:white;box-shadow:0 3px 10px #123b4a0b;min-width:0}
.chs-registration .section-box legend {font-size:1.2rem;font-weight:700;color:#1f5968;padding:0 8px}
.chs-registration label {display:block;margin:0;font-weight:600;color:#174b5b;font-size:.94rem}
.chs-registration input:not([type=checkbox]):not([type=submit]),.chs-registration select,.chs-registration textarea {box-sizing:border-box;width:100%;max-width:100%;min-height:43px;margin:0;padding:9px 12px;border:1px solid #b8cdd3;border-radius:6px;background:white;color:#243b44;font:inherit;box-shadow:none}
.chs-registration textarea {min-height:88px;resize:vertical}
.chs-registration input:focus,.chs-registration select:focus,.chs-registration textarea:focus {outline:2px solid #50bfd3;outline-offset:1px}
.chs-registration .field-help {font-size:.83rem;color:#536a72;margin:0}
.chs-registration .required {color:#a32a2a}
.chs-registration .save-button {display:block;width:100%;max-width:100%;padding:13px 20px;background:#1f5968;color:white;border:0;border-radius:7px;font:inherit;font-weight:700;cursor:pointer}
.chs-registration .save-button:hover {background:#17444f}
.chs-registration .form-actions {display:flex;flex-direction:column;gap:12px;align-items:stretch}
.chs-registration .form-actions a {text-align:center;color:#1f5968}
.chs-registration #alert-reason-fields {grid-column:1/-1}
.chs-registration #alert-reason-fields .registration-grid {margin-top:4px}
@media(max-width:680px){.chs-registration .registration-grid{grid-template-columns:1fr}.chs-registration .section-box{padding:18px}.chs-registration .registration-field.wide{grid-column:auto}}
</style>
<header class="hero-header"><div class="center-header"><h1>Register Participant</h1></div></header>
<main class="chs-registration">
<form class="signup-form" method="post">
<p class="registration-intro">Enter participant information below. <span class="required">*</span> indicates a required field.</p>
<fieldset class="section-box"><legend>Personal &amp; Contact Information</legend>
<div class="registration-grid">
<div class="registration-field"><label for="first_name"><span class="required">*</span> First Name</label><input type="text" id="first_name" name="first_name" maxlength="50" autocomplete="given-name" required placeholder="First name"></div>
<div class="registration-field"><label for="last_name"><span class="required">*</span> Last Name</label><input type="text" id="last_name" name="last_name" maxlength="50" autocomplete="family-name" required placeholder="Last name"></div>
<div class="registration-field"><label for="phone1"><span class="required">*</span> Phone Number</label><input type="tel" id="phone1" name="phone1" autocomplete="tel" required placeholder="540-555-0101"></div>
<div class="registration-field"><label for="email">Email (Optional)</label><input type="email" id="email" name="email" maxlength="100" autocomplete="email" placeholder="Email address"></div>
<div class="registration-field"><label for="preferred_language"><span class="required">*</span> Preferred Language</label><select id="preferred_language" name="preferred_language" required><option value="English" selected>English</option><option value="Spanish">Spanish</option><option value="Other">Other</option></select></div>
</div></fieldset>
<fieldset class="section-box"><legend>Address Information</legend>
<div class="registration-grid">
<div class="registration-field wide"><label for="street_address"><span class="required">*</span> Street Address</label><input type="text" id="street_address" name="street_address" maxlength="100" autocomplete="street-address" required placeholder="Street address"></div>
<div class="registration-field"><label for="city">City</label><input type="text" id="city" name="city" maxlength="50" autocomplete="address-level2" placeholder="City"></div>
<div class="registration-field"><label for="state">State</label><select id="state" name="state"><option value="VA" selected>Virginia</option><option value="MD">Maryland</option><option value="WV">West Virginia</option><option value="DC">District of Columbia</option><option value="NC">North Carolina</option><option value="other">Other</option></select></div>
<div class="registration-field"><label for="zip">ZIP Code</label><input type="text" id="zip" name="zip" pattern="[0-9]{5}" maxlength="5" inputmode="numeric" autocomplete="postal-code" title="Enter a five-digit ZIP code" placeholder="ZIP code"></div>
</div></fieldset>
<fieldset class="section-box"><legend>Pet Pantry Information</legend>
<div class="registration-grid">
<div class="registration-field"><label for="participant_location"><span class="required">*</span> Participant Location</label><select id="participant_location" name="participant_location" required><option value="" disabled selected>Select a location</option><option value="Empower">Empower</option><option value="Senior Center">Senior Center</option><option value="Home Delivery">Home Delivery</option></select></div>
<div class="registration-field"><label for="registration_date"><span class="required">*</span> Registration Date</label><input type="date" id="registration_date" name="registration_date" value="<?php echo date('Y-m-d'); ?>" required></div>
<div class="registration-field"><label for="participant_status"><span class="required">*</span> Participant Status</label><select id="participant_status" name="participant_status" required><option value="Active" selected>Active</option><option value="Inactive">Inactive</option><option value="Expired">Expired</option><option value="DND">DND (Do Not Distribute)</option></select></div>
<div class="registration-field"><label for="participant_alert">Alert / Flag</label>
<select id="participant_alert" name="participant_alert"><option value="No" selected>No</option><option value="Flagged">Flagged</option></select>

</div>
<div id="alert-reason-fields" style="display:none"><div class="registration-grid">
<div class="registration-field"><label for="alert_type"><span class="required">*</span> Alert Reason</label><select id="alert_type" name="alert_type"><option value="">Select a reason</option><?php foreach (participant_alert_types() as $alertType): ?><option value="<?php echo hsc($alertType); ?>"><?php echo hsc($alertType); ?></option><?php endforeach; ?></select></div>
<div class="registration-field wide"><label for="alert_reason">Alert Details</label><textarea id="alert_reason" name="alert_reason" rows="3" maxlength="2000" placeholder="Optional details for staff"></textarea></div>
</div></div>
<div class="registration-field wide"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3" placeholder="Additional participant notes (optional)"></textarea></div>
</div></fieldset>
<div class="form-actions"><input class="save-button" type="submit" value="Save Participant &amp; Add Pets"><a href="index.php">Return to Dashboard</a></div>
</form></main>
<script>
(function(){
 const flag=document.getElementById('participant_alert');
 const fields=document.getElementById('alert-reason-fields');
 const type=document.getElementById('alert_type');
 if(!flag||!fields||!type)return;
 function update(){const flagged=flag.value==='Flagged';fields.style.display=flagged?'':'none';type.required=flagged;}
 flag.addEventListener('change',update);
 document.addEventListener('DOMContentLoaded',update);
 update();
})();
</script>
