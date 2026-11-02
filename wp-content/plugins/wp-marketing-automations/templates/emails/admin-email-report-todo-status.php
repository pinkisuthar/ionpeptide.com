<?php
defined( 'ABSPATH' ) || exit;
?>
<table cellpadding="0" cellspacing="0" border="0" bgcolor="" align="center" role="presentation"
       class="bwfbe-block-section-container bwfbe-block-section bwfbe-block-253eaea"
       style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; width: 640px;"
       width="640">
    <tbody>
    <tr
            style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
        <td style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; background-color: #ffffff;"
            bgcolor="#ffffff">
            <!--[if mso | IE]><table cellpadding="0" cellspacing="0" border="0" align="center" style="width:100%" role="presentation" width="640"><tr><td style="line-height:0;font-size:0;-mso-line-height-rule:exactly"><![endif]-->
            <div class="bwfbe-block-section-outer-container"
                 style="margin: 0 auto; width: 640px; background-color: #ffffff;">
                <table cellpadding="0" cellspacing="0" border="0" align="center"
                       style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; line-height: normal; background-color: #ffffff; width: 100%; border-collapse: separate;"
                       role="presentation" width="100%" class="bwfbe-block-section-inner-container"
                       bgcolor="#ffffff">
                    <tbody>
                    <tr
                            style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                        <td
                                style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; font-size: 0px; border-collapse: separate; padding: 0 16px 16px; mso-padding-alt: 0 16px 16px;">
                            <!--[if mso | IE]><table cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate" role="presentation"><tbody><tr><![endif]--><!--[if mso | IE]><td style="width: 608px;vertical-align: top;" valign="top"><![endif]-->
                            <div class="bwf-email-inner-column-wrapper bwf-email-inner-column-wrapper-b88f6c4"
                                 style="font-size: 0px; border-collapse: separate; display: table-cell; vertical-align: top; mso-border-alt: none; width: 608px;">
                                <table cellpadding="0" cellspacing="0" border="0"
                                       style="border-spacing: 0px; border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;"
                                       role="presentation"
                                       class="bwf-email-inner-column bwf-email-inner-column-b88f6c4"
                                       width="100%">
                                    <tbody>
                                    <tr
                                            style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                        <td class="bwfbe-block-html bwfbe-block-html-e48f206"
                                            style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; padding: 16px 0px 8px 0px; font-size: 16px; font-family: arial,helvetica,sans-serif;">
                                            <table cellpadding="0" cellspacing="0"
                                                   border="0" align="left"
                                                   style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; width: 100%;"
                                                   role="presentation" width="100%">
                                                <tbody>
                                                <tr
                                                        style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                                    <td
                                                            style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; line-height: 1.5;">
                                                        <table cellpadding="0"
                                                               cellspacing="0" border="0"
                                                               style="mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; width: 100%; max-width: 600px; border-collapse: collapse;"
                                                               width="100%">
                                                            <tbody>
															<?php foreach( $todolist as $list ){
																$border_bottom = ( isset( $list['last'] ) && $list['last'] === true ) ? 'none' : '1px solid #e0e0e0';
																?>
                                                                <tr
                                                                        style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                                                    <td
                                                                            style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; line-height: 1.5; border-bottom: <?php echo intval($border_bottom) ?>; padding: 10px 0;">
                                                                        <table
                                                                                cellpadding="0"
                                                                                cellspacing="0"
                                                                                border="0"
                                                                                style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; width: 100%;"
                                                                                width="100%">
                                                                            <tbody>
                                                                            <tr
                                                                                    style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                                                                <td
                                                                                        style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; line-height: 1.5; font-family: Arial, sans-serif; font-size: 14px; color: #333333;">
																					<?php echo $list['title']; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                                                </td>
																				<?php if( $list['status'] === 'pro' ){
																					?>
                                                                                    <td style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; line-height: 1.5; width: 130px; text-align: right;"
                                                                                        width="130"
                                                                                        align="right"><a href="<?php echo esc_url( $upgrade_link ); ?>"
                                                                                                         style="color: #0073aa; text-decoration: underline; font-family: Arial, sans-serif; font-size: 14px;">
                                                                                            <b>
			                                                                                    <?php echo esc_html__( 'Upgrade to Pro', 'wp-marketing-automations' );?>
                                                                                            </b>
                                                                                        </a>
                                                                                    </td>
																					<?php
																				} elseif( $list['status'] === 'active' ){
																					?>
                                                                                    <td style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; line-height: 1.5; width: 30px; text-align: right;"
                                                                                        width="30"
                                                                                        align="right">
                                                                                                                        <span
                                                                                                                            style="color: #4CAF50; font-size: 18px;">✅</span>
                                                                                    </td>
																					<?php
																				} else {
																					?>
                                                                                    <td style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; line-height: 1.5; width: 50px; text-align: right;"
                                                                                        width="50"
                                                                                        align="right">
                                                                                        <a href="<?php echo esc_url($list['link']); ?>"
                                                                                           style="color: #0073aa; text-decoration: underline; font-family: Arial, sans-serif; font-size: 14px;">
                                                                                            <b>
																								<?php esc_html_e( 'Setup', 'wp-marketing-automations' );?>
                                                                                            </b>
                                                                                        </a>
                                                                                    </td>
																					<?php
																				}?>
                                                                            </tr>
                                                                            </tbody>
                                                                        </table>
                                                                    </td>
                                                                </tr>
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