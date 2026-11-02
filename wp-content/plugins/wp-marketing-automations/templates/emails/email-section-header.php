<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<table cellpadding="0" cellspacing="0" border="0" bgcolor="" align="center" role="presentation" class="bwfbe-block-section-container bwfbe-block-section bwfbe-block-dcb37e6" style="border-collapse: collapse; width: 640px;" width="640">
    <tbody>
    <tr>
        <td style="background-color: #ffffff;" bgcolor="#ffffff">
            <table cellpadding="0" cellspacing="0" border="0" align="center" style="width: 100%; border-collapse: collapse; background-color: #ffffff;" role="presentation" class="bwfbe-block-section-inner-container" bgcolor="#ffffff" width="100%">
                <tbody>
                <tr>
                    <td style="padding: 24px 36px;">
                        <table cellpadding="0" cellspacing="0" border="0" style="width: 100%; border-collapse: collapse;" role="presentation" class="bwf-email-inner-column bwf-email-inner-column-87a94f3" width="100%">
                            <tbody>
                            <tr>
                                <td class="bwf-email-text bwf-email-text-9c3539c" style="padding-bottom: 8px; mso-padding-alt: 0 0 8px 0;text-align: center;">
                                    <p class="bwf-block-text-inner-container" style="margin: 0; font-size: 36px; font-family: Arial, Helvetica, sans-serif; font-weight: bold; color: #000;">
										<?php echo wp_strip_all_tags( $title ); //phpcs:ignore WordPress.Security.EscapeOutput ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <td class="bwf-email-text bwf-email-text-8c73b4e" style="text-align: center;">
                                    <p class="bwf-block-text-inner-container" style="margin: 0; font-size: 16px; font-family: Arial, Helvetica, sans-serif; color: #000;">
										<?php echo wp_strip_all_tags( $subtitle ); //phpcs:ignore WordPress.Security.EscapeOutput ?>
                                    </p>
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>
                </tbody>
            </table>
        </td>
    </tr>
    </tbody>
</table>

