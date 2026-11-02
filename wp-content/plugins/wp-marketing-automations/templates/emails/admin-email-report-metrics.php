<?php
defined( 'ABSPATH' ) || exit;
?>
<table cellpadding="0" cellspacing="0" border="0" bgcolor="" align="center" role="presentation"
       class="bwfbe-block-section-container bwfbe-block-section bwfbe-block-051356b"
       style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; width: 640px;"
       width="640">
    <tbody>
    <tr style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
        <td style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; background-color: #ffffff;"
            bgcolor="#ffffff">
            <!--[if mso | IE]><table cellpadding="0" cellspacing="0" border="0" align="center" style="width:100%" role="presentation" width="640"><tr><td style="line-height:0;font-size:0;-mso-line-height-rule:exactly"><![endif]-->
            <div class="bwfbe-block-section-outer-container"
                 style="margin: 0 auto; width: 640px; background-color: #ffffff;">
                <table cellpadding="0" cellspacing="0" border="0" align="center"
                       style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; line-height: normal; background-color: #ffffff; width: 100%; border-collapse: separate;"
                       role="presentation" width="100%" class="bwfbe-block-section-inner-container" bgcolor="#ffffff">
                    <tbody>
                    <tr
                            style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                        <td
                                style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; font-size: 0px; border-collapse: separate; padding: 16px; mso-padding-alt: 16px;">
                            <!--[if mso | IE]><table cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate" role="presentation"><tbody><tr><![endif]--><!--[if mso | IE]><td style="width: 608px;vertical-align: top;" valign="top"><![endif]-->
                            <div class="bwf-email-inner-column-wrapper bwf-email-inner-column-wrapper-cdabb86"
                                 style="font-size: 0px; border-collapse: separate; display: table-cell; vertical-align: top; mso-border-alt: none; width: 608px;">
                                <table cellpadding="0" cellspacing="0" border="0"
                                       style="border-spacing: 0px; border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;"
                                       role="presentation"
                                       class="bwf-email-inner-column bwf-email-inner-column-cdabb86" width="100%">
                                    <tbody>
                                    <tr
                                            style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                        <td class="bwfbe-block-html bwfbe-block-html-6c3cf67"
                                            style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; padding: 0px 0px 0px 0px; font-size: 16px; font-family: arial,helvetica,sans-serif;">
                                            <table cellpadding="0" cellspacing="0" border="0" align="left"
                                                   style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; width: 100%;"
                                                   role="presentation" width="100%">
                                                <tbody>
                                                <tr
                                                        style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                                    <td
                                                            style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; line-height: 1.5;">


                                                        <table cellpadding="0" cellspacing="0"
                                                               border="0" class="metrics-table"
                                                               style="border-spacing: 0px; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; max-width: 600px; border-collapse: collapse; font-family: arial, helvetica, sans-serif;"
                                                               width="100%">
                                                            <tbody>
                                                            <?php
                                                            $total_tiles = count( $tile_data );
                                                            foreach ( $tile_data as $key => $tile ) {
                                                                ?>
                                                                <tr style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                                                    <?php
                                                                    foreach ( $tile as $inner_key => $col ) {
	                                                                    $padding = ( 0 === $inner_key % 2 ) ? 'padding-right: 10px' : 'padding-left: 0';
                                                                        ?>
                                                                        <td class="metric-cell" id="total-contacts" style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; line-height: 1.5; width: 50%; vertical-align: top; <?php echo esc_html( $padding ) ?>"
                                                                            width="50%" valign="top">
                                                                            <table cellpadding="0" cellspacing="0" border="0"
                                                                                   style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; width: 100%;"
                                                                                   width="100%">
                                                                                <tbody>
                                                                                <tr style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                                                                    <td style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; line-height: 1.5; font-family: arial, helvetica, sans-serif; font-size: 16px; color: #000000; padding-bottom: 5px;" class="metric-label">
                                                                                        <b><?php echo $col['text']; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></b>
                                                                                    </td>
                                                                                </tr>
                                                                                <tr style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                                                                    <td style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; line-height: 1.5; font-family: arial, helvetica, sans-serif; font-size: 48px; font-weight: bold; color: #000000; padding-bottom: 5px;">
                                                                                        <?php echo $col['count']; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	                                                                                    <?php if ( ! empty( $col['count_suffix'] ) ) {
		                                                                                    ?>
                                                                                            <span style="font-size: 18px; font-weight: normal;">
                                                                                                    <?php echo $col['count_suffix']; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                                                                </span>
		                                                                                    <?php
	                                                                                    } ?>
                                                                                    </td>
                                                                                </tr>
                                                                                <tr style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                                                                    <td class="metric-delta" style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; line-height: 1.5;">
	                                                                                    <?php $color = $col['percentage_change_positive'] ? '#089D61' : '#FF0000'; ?>
                                                                                        <span style="font-family: arial, helvetica, sans-serif;color: <?php echo esc_html( $color ); ?>; font-size: 16px;">
                                                                                            <?php echo $col['percentage_change']; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                                                        </span>
                                                                                        <span style="font-family: arial, helvetica, sans-serif;color: #666666; font-size: 14px;">
                                                                                        <?php echo $col['previous_text']; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                                                    </span>
                                                                                    </td>
                                                                                </tr>
                                                                                </tbody>
                                                                            </table>
                                                                        </td>
                                                                        <?php
                                                                    }
                                                                    ?>
                                                                </tr>
                                                                <?php if ( ( $key+1 ) !== $total_tiles  ) {
                                                                    ?>
                                                                    <tr style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                                                        <td class="spacer-row" colspan="2"
                                                                            style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; line-height: 1.5; height: 32px;"
                                                                            height="32"></td>
                                                                    </tr>
                                                                    <?php
                                                                } ?>
                                                                <?php
                                                            } ?>
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
                            </div>
                            <!--[if mso | IE]></td><![endif]--><!--[if mso | IE]></tr></tbody></table><![endif]-->
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div><!--[if mso | IE]></td></tr></table><![endif]-->
        </td>
    </tr>
    </tbody>
</table>