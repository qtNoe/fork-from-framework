<?php 
/**
 * View for mail verification feedback
 */

return ["head" => function($opt) { ?>
	<style>
		.login-error {
			color: red;
		}
	</style>
<?php }, "body" => function($opt) { ?>

  <div style="max-width: 1000px; margin: auto; margin-top: 25vh">
    An email was sent to you. Please check your inbox and your spam directory.
	</div>
<?php }]; ?>