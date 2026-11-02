<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <!--[if gte mso 9]><xml><o:officeDocumentSettings><o:allowPNG></o:allowPNG><o:pixelsPerInch>96</o:pixelsPerInch></o:officeDocumentSettings></xml><![endif]-->
    <meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <!--[if !mso]><meta http-equiv="X-UA-Compatible" content="IE=edge"/><![endif]-->
    <title><?php echo get_bloginfo( 'name', 'display' ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></title>
    <!--[if (gte mso 9)|(IE)]><style>
        ul li {
            mso-special-format: bullet;
        }
    </style><![endif]--><style id="email-stylesheet">a.bwf-menu-item {
            text-decoration: none;
        }p{margin:0}table,tr,td{border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt;border-spacing:0px}img{line-height:100%}td > img{pointer-events:none !important}.screen-reader-text{display:none}@media only screen and (max-width:768px){.bwfbe-block-image.bwfbe-block-image-86407c8 > table{margin-left:auto !important;margin-right:auto !important;float:none !important}.bwfbe-block-image.bwfbe-block-image-86407c8 .bwf-email-image_wrap{width:100% !important;text-align:center !important}.bwfbe-block-image.bwfbe-block-image-86407c8 img{margin-right:auto !important;margin-left:auto !important} }@media only screen and (max-width:768px){.bwf-email-text.bwf-email-text-bdc66e9 .bwf-block-text-container{text-align:center !important;line-height:1.2 !important}.bwf-email-text.bwf-email-text-bdc66e9 .bwf-block-text-inner-container,.bwf-email-text.bwf-email-text-bdc66e9 .bwf-block-text-inner-container span.has-font-size{line-height:1.2 !important} }@media only screen and (max-width:768px){.bwf-email-text.bwf-email-text-15ea1e0 .bwf-block-text-container{font-size:14px !important}.bwf-email-text.bwf-email-text-15ea1e0 .bwf-block-text-inner-container,.bwf-email-text.bwf-email-text-15ea1e0 .bwf-block-text-inner-container span.has-font-size{font-size:14px !important} }@media only screen and (max-width:768px){.bwf-email-text.bwf-email-text-abfa5fc .bwf-block-text-container{font-size:20px !important}.bwf-email-text.bwf-email-text-abfa5fc .bwf-block-text-inner-container,.bwf-email-text.bwf-email-text-abfa5fc .bwf-block-text-inner-container span.has-font-size{font-size:20px !important} }@media only screen and (max-width:768px){.bwf-email-text.bwf-email-text-2cfd4a0 .bwf-block-text-container{font-size:14px !important}.bwf-email-text.bwf-email-text-2cfd4a0 .bwf-block-text-inner-container,.bwf-email-text.bwf-email-text-2cfd4a0 .bwf-block-text-inner-container span.has-font-size{font-size:14px !important} }@media only screen and (max-width:768px){.bwfbe-block-btn.bwfbe-block-btn-b434bf2{padding:16px 0px 0px 0px! important;mso-padding-alt:16px 0px 0px 0px! important}.bwfbe-block-btn.bwfbe-block-btn-b434bf2 .bwfbe-block-btn-container{width:100% !important}.bwfbe-block-btn.bwfbe-block-btn-b434bf2 .bwfbe-block-btn-container .bwfbe-btn-text-wrap{font-size:14px !important;font-family:arial,helvetica,sans-serif !important;mso-padding-alt:8px 8px 8px 8px;text-align:center !important}.bwfbe-block-btn.bwfbe-block-btn-b434bf2 .bwfbe-block-btn-container .bwfbe-block-btn-content{padding:8px 8px 8px 8px ! important;mso-padding-alt:0;font-size:14px !important;font-family:arial,helvetica,sans-serif !important} }@media only screen and (max-width:768px){.bwf-email-text.bwf-email-text-c47cbed .bwf-block-text-container{font-size:20px !important}.bwf-email-text.bwf-email-text-c47cbed .bwf-block-text-inner-container,.bwf-email-text.bwf-email-text-c47cbed .bwf-block-text-inner-container span.has-font-size{font-size:20px !important} }@media only screen and (max-width:768px){.bwf-email-text.bwf-email-text-88f0975 .bwf-block-text-container{font-size:14px !important}.bwf-email-text.bwf-email-text-88f0975 .bwf-block-text-inner-container,.bwf-email-text.bwf-email-text-88f0975 .bwf-block-text-inner-container span.has-font-size{font-size:14px !important} }@media only screen and (max-width:768px){.metrics-table{width:100% !important;border-spacing:0 !important}.metric-cell{padding-top:10px !important;padding-bottom:10px !important}.metric-cell td{font-size:18px !important}.metric-cell .metric-label{font-size:14px !important}.spacer-row{height:0 !important}.metric-delta span{font-size:14px !important} }@media only screen and (max-width:768px){.bwfbe-block-html.bwfbe-block-html-6c3cf67{font-size:16px}.bwfbe-block-html.bwfbe-block-html-6c3cf67 td{line-height:1.5} }@media only screen and (max-width:768px){.bwf-email-text.bwf-email-text-46b87a9 .bwf-block-text-container{font-size:20px !important}.bwf-email-text.bwf-email-text-46b87a9 .bwf-block-text-inner-container,.bwf-email-text.bwf-email-text-46b87a9 .bwf-block-text-inner-container span.has-font-size{font-size:20px !important} }@media only screen and (max-width:768px){.bwf-email-text.bwf-email-text-fbbffc7 .bwf-block-text-container{font-size:14px !important}.bwf-email-text.bwf-email-text-fbbffc7 .bwf-block-text-inner-container,.bwf-email-text.bwf-email-text-fbbffc7 .bwf-block-text-inner-container span.has-font-size{font-size:14px !important} }@media only screen and (max-width:768px){.bwf-email-text.bwf-email-text-77d0c99 .bwf-block-text-container{font-size:14px !important}.bwf-email-text.bwf-email-text-77d0c99 .bwf-block-text-inner-container,.bwf-email-text.bwf-email-text-77d0c99 .bwf-block-text-inner-container span.has-font-size{font-size:14px !important} }@media only screen and (max-width:768px){.bwf-email-text.bwf-email-text-f6e20cc .bwf-block-text-container{font-size:14px !important}.bwf-email-text.bwf-email-text-f6e20cc .bwf-block-text-inner-container,.bwf-email-text.bwf-email-text-f6e20cc .bwf-block-text-inner-container span.has-font-size{font-size:14px !important} }@media only screen and (max-width:768px){.bwfbe-block-btn.bwfbe-block-btn-c0df4ae{padding:8px 0px 0px 0px! important;mso-padding-alt:8px 0px 0px 0px! important}.bwfbe-block-btn.bwfbe-block-btn-c0df4ae .bwfbe-block-btn-container{width:auto !important}.bwfbe-block-btn.bwfbe-block-btn-c0df4ae .bwfbe-block-btn-container .bwfbe-btn-text-wrap{font-size:13px !important;font-family:arial,helvetica,sans-serif !important;text-align:center !important}.bwfbe-block-btn.bwfbe-block-btn-c0df4ae .bwfbe-block-btn-container .bwfbe-block-btn-content{mso-padding-alt:0;font-size:13px !important;font-family:arial,helvetica,sans-serif !important} }@media only screen and (max-width:768px){.bwfbe-block-image.bwfbe-block-image-d34c58a .bwf-email-image_wrap{width:100% !important}.bwfbe-block-image.bwfbe-block-image-d34c58a img{width:100% !important} }@media only screen and (max-width:768px){.bwfbe-block-image.bwfbe-block-image-48d0535 .bwf-email-image_wrap{width:100% !important}.bwfbe-block-image.bwfbe-block-image-48d0535 img{width:100% !important} }@media only screen and (max-width:768px){.bwf-email-text.bwf-email-text-bb16d3c .bwf-block-text-container{font-size:14px !important}.bwf-email-text.bwf-email-text-bb16d3c .bwf-block-text-inner-container,.bwf-email-text.bwf-email-text-bb16d3c .bwf-block-text-inner-container span.has-font-size{font-size:14px !important} }@media only screen and (max-width:768px){.bwf-email-text.bwf-email-text-42f4a77 .bwf-block-text-container{font-size:14px !important}.bwf-email-text.bwf-email-text-42f4a77 .bwf-block-text-inner-container,.bwf-email-text.bwf-email-text-42f4a77 .bwf-block-text-inner-container span.has-font-size{font-size:14px !important} }@media only screen and (max-width:768px){.bwfbe-block-btn.bwfbe-block-btn-d963d84{padding:8px 0px 0px 0px! important;mso-padding-alt:8px 0px 0px 0px! important}.bwfbe-block-btn.bwfbe-block-btn-d963d84 .bwfbe-block-btn-container{width:auto !important}.bwfbe-block-btn.bwfbe-block-btn-d963d84 .bwfbe-block-btn-container .bwfbe-btn-text-wrap{font-size:13px !important;font-family:arial,helvetica,sans-serif !important;text-align:center !important}.bwfbe-block-btn.bwfbe-block-btn-d963d84 .bwfbe-block-btn-container .bwfbe-block-btn-content{mso-padding-alt:0;font-size:13px !important;font-family:arial,helvetica,sans-serif !important} }@media only screen and (max-width:768px){.bwf-email-text.bwf-email-text-9c3539c .bwf-block-text-container{font-size:20px !important}.bwf-email-text.bwf-email-text-9c3539c .bwf-block-text-inner-container,.bwf-email-text.bwf-email-text-9c3539c .bwf-block-text-inner-container span.has-font-size{font-size:20px !important} }@media only screen and (max-width:768px){.bwf-email-text.bwf-email-text-8c73b4e{padding:0px 0px 16px 0px! important}.bwf-email-text.bwf-email-text-8c73b4e .bwf-block-text-container{font-size:14px !important}.bwf-email-text.bwf-email-text-8c73b4e .bwf-block-text-inner-container,.bwf-email-text.bwf-email-text-8c73b4e .bwf-block-text-inner-container span.has-font-size{font-size:14px !important} }@media only screen and (max-width:768px){.bwfbe-block-html.bwfbe-block-html-e48f206{font-size:16px}.bwfbe-block-html.bwfbe-block-html-e48f206 td{line-height:1.5} }@media only screen and (max-width:768px){.bwf-email-text.bwf-email-text-8f797fa{padding:0px 0px 4px 0px! important}.bwf-email-text.bwf-email-text-8f797fa .bwf-block-text-container{text-align:center !important} }@media only screen and (max-width:768px){.bwfbe-block-btn.bwfbe-block-btn-829b566{padding:8px 0px 0px 0px! important;mso-padding-alt:8px 0px 0px 0px! important;text-align:-webkit-center !important}.bwfbe-block-btn.bwfbe-block-btn-829b566 .bwfbe-block-btn-container{width:auto !important;margin-right:auto !important;margin-left:auto !important}.bwfbe-block-btn.bwfbe-block-btn-829b566 .bwfbe-block-btn-container .bwfbe-btn-text-wrap{text-align:center !important}.bwfbe-block-btn.bwfbe-block-btn-829b566 .bwfbe-block-btn-container .bwfbe-block-btn-content{mso-padding-alt:0} }@media only screen and (max-width:768px){.metrics-table{width:100% !important;border-spacing:0 !important}.metric-cell{padding-top:10px !important;padding-bottom:10px !important}.metric-cell td{font-size:18px !important}.metric-cell .metric-label{font-size:14px !important}.spacer-row{height:0 !important}.metric-delta span{font-size:14px !important} }@media only screen and (max-width:768px){.bwf-email-inner-column.bwf-email-inner-column-71f69c7{padding:0px 0px 4px 0px! important} }@media only screen and (max-width:768px){.bwf-email-inner-column.bwf-email-inner-column-347df34{padding:4px 0px 0px 0px! important} }@media only screen and (max-width:768px){.bwf-email-inner-column.bwf-email-inner-column-3c11490{padding:4px 0px 0px 0px! important} }@media only screen and (max-width:768px){.bwf-email-inner-column.bwf-email-inner-column-f0bb76b{padding:0px 0px 4px 0px! important} }@media only screen and (max-width:768px){.bwf-email-inner-column.bwf-email-inner-column-083019f .bwf-email-inner-column-container{padding:12px 12px 4px 12px ! important}.bwf-email-inner-column-wrapper.bwf-email-inner-column-wrapper-083019f{border-radius:8px 8px 0px 0px !important} }@media only screen and (max-width:768px){.bwf-email-inner-column.bwf-email-inner-column-4f8575e .bwf-email-inner-column-container{padding:0px 0px 16px 0px! important}.bwf-email-inner-column-wrapper.bwf-email-inner-column-wrapper-4f8575e{border-radius:0px 0px 8px 8px !important} }@media only screen and (max-width:768px){.bwfbe-block-section.bwfbe-block-86396ac .bwfbe-block-section-inner-container > tbody > tr > td{padding:16px 4px! important;mso-padding-alt:16px 4px! important}.bwf-email-inner-column-wrapper{width:100% !important;display:inline-block !important;box-sizing:border-box} }@media only screen and (max-width:768px){.bwfbe-section-gap-86396ac,.bwfbe-section-gap-86396ac > table{height:16px !important;width:100% !important} }@media only screen and (max-width:768px){.bwfbe-block-section.bwfbe-block-4bf2f08 .bwfbe-block-section-inner-container > tbody > tr > td{padding:36px 16px! important;mso-padding-alt:36px 16px! important}.bwf-email-inner-column-wrapper{width:100% !important;display:inline-block !important;box-sizing:border-box} }@media only screen and (max-width:768px){.bwfbe-block-section.bwfbe-block-c71bd4e .bwfbe-block-section-inner-container > tbody > tr > td{padding:36px 16px 12px! important;mso-padding-alt:36px 16px 12px! important}.bwf-email-inner-column-wrapper{width:100% !important;display:inline-block !important;box-sizing:border-box} }@media only screen and (max-width:768px){.metrics-table{width:100% !important;border-spacing:0 !important}.metric-cell{padding-top:10px !important;padding-bottom:10px !important}.metric-cell td{font-size:18px !important}.metric-cell .metric-label{font-size:14px !important}.spacer-row{height:0 !important}.metric-delta span{font-size:14px !important} }@media only screen and (max-width:768px){.bwf-email-inner-column-wrapper{width:100% !important;display:inline-block !important;box-sizing:border-box} }@media only screen and (max-width:768px){.bwf-email-inner-column-wrapper{width:100% !important;display:inline-block !important;box-sizing:border-box} }@media only screen and (max-width:768px){.bwfbe-block-section.bwfbe-block-8d8dfb1 .bwfbe-block-section-inner-container > tbody > tr > td{padding:24px 12px! important;mso-padding-alt:24px 12px! important} }@media only screen and (max-width:768px){.bwfbe-section-gap-8d8dfb1,.bwfbe-section-gap-8d8dfb1 > table{width:16px !important} }@media only screen and (max-width:768px){.bwfbe-block-section.bwfbe-block-e4ee9e6 .bwfbe-block-section-inner-container > tbody > tr > td{padding:24px 12px! important;mso-padding-alt:24px 12px! important} }@media only screen and (max-width:768px){.bwfbe-section-gap-e4ee9e6,.bwfbe-section-gap-e4ee9e6 > table{width:16px !important} }@media only screen and (max-width:768px){.bwfbe-block-section.bwfbe-block-dcb37e6 .bwfbe-block-section-inner-container > tbody > tr > td{padding:36px 16px 12px! important;mso-padding-alt:36px 16px 12px! important}.bwf-email-inner-column-wrapper{width:100% !important;display:inline-block !important;box-sizing:border-box} }@media only screen and (max-width:768px){.bwf-email-inner-column-wrapper{width:100% !important;display:inline-block !important;box-sizing:border-box} }@media only screen and (max-width:768px){.bwf-email-inner-column-wrapper{width:100% !important;display:inline-block !important;box-sizing:border-box} }@media only screen and (max-width:768px){.bwfbe-section-gap-dee5d03,.bwfbe-section-gap-dee5d03 > table{height:0px !important;width:100% !important} }@media only screen and (max-width:768px){.bwfbe-block-section.bwfbe-block-e7c0384 .bwfbe-block-section-inner-container > tbody > tr > td{padding:24px 12px 16px! important;mso-padding-alt:24px 12px 16px! important}.bwf-email-inner-column-wrapper{width:100% !important;display:inline-block !important;box-sizing:border-box} }@media only screen and (max-width:768px){.metrics-table{width:100% !important;border-spacing:0 !important}.metric-cell{padding-top:10px !important;padding-bottom:10px !important}.metric-cell td{font-size:18px !important}.metric-cell .metric-label{font-size:14px !important}.spacer-row{height:0 !important}.metric-delta span{font-size:14px !important} }@media only screen and (max-width: 768px) {  .bwfbe-block-section, .bwfbe-block-section .bwfbe-block-section-outer-container {width: 100% !important;}  }</style><style>body::-webkit-scrollbar { width: 6px; height: 5px; } body::-webkit-scrollbar-thumb { background: #666; } body::-webkit-scrollbar-track { background: #dedede; }</style>
    <style type="text/css">@media only screen and (max-width: 768px) {.metrics-table {width: 100% !important;border-spacing: 0 !important;}.metric-cell {padding-top: 10px !important;padding-bottom: 10px !important;}  .metric-cell td {font-size: 18px !important;}.metric-cell .metric-label {font-size: 14px !important;}  .spacer-row {height: 0 !important;} .metric-delta span {font-size: 14px !important;}  }</style>
</head>
<body style="padding:0;margin:0;webkit-text-size-adjust:100%;background-color:#ffffff ;direction:<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
<div style="background-color:#ffffff ">

    <table cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" align="center"
           style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; width: 100%;"
           role="presentation" class="bwfbe-block-row bwfbe-block-2f44995" width="100%">
        <tbody>
        <tr
            style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
            <td
                style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                <table cellpadding="0" cellspacing="0" border="0" bgcolor="" align="center" role="presentation"
                       class="bwfbe-block-section-container bwfbe-block-section bwfbe-block-86396ac"
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
                                            <!--[if mso | IE]><table cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate" role="presentation"><tbody><tr><![endif]--><!--[if mso | IE]><td style="width: 296px;vertical-align: middle;" valign="middle"><![endif]-->
                                            <div class="bwf-email-inner-column-wrapper bwf-email-inner-column-wrapper-63aba4e"
                                                 style="font-size: 0px; border-collapse: separate; display: table-cell; vertical-align: middle; mso-border-alt: none; width: 296px;">
                                                <table cellpadding="0" cellspacing="0" border="0"
                                                       style="border-spacing: 0px; border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;"
                                                       role="presentation"
                                                       class="bwf-email-inner-column bwf-email-inner-column-63aba4e" width="100%">
                                                    <tbody>
                                                    <tr
                                                            style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                                        <td class="bwfbe-block-image bwfbe-block-image-86407c8"
                                                            style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; padding: 0px 0px 0px 0px; mso-padding-alt: 0px 0px 0px 0px;">
                                                            <table cellpadding="0" cellspacing="0" border="0" align="left"
                                                                   role="presentation"
                                                                   style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                                                <tbody>
                                                                <tr
                                                                        style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                                                    <td class="bwf-email-image_wrap"
                                                                        style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; width: 100%; text-align: left;"
                                                                        width="100%" align="left"><img
                                                                                src="<?php echo BWFAN_PLUGIN_URL; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/assets/img/logo.png"
                                                                                alt=" "
                                                                                style="line-height: 100%; height: auto; display: block; text-decoration: none; max-width: 100%; font-size: 13px; border: 0; outline: none; box-sizing: border-box; width: 178px; margin-right: auto; margin-left: 0; pointer-events: none;"
                                                                                width="178"></td>
                                                                </tr>
                                                                </tbody>
                                                            </table>
                                                        </td>
                                                    </tr>
                                                    </tbody>
                                                </table>
                                            </div><!--[if mso | IE]></td><![endif]-->

                                            <!--[if mso | IE]><td style="width: 16px;vertical-align:top;" valign="top><![endif]-->
                                            <div style="border-collapse: separate; font-size: 0; vertical-align: top; display: table-cell; width: 16px;"
                                                 class="bwfbe-section-gap-86396ac">
                                                <table cellpadding="0" cellspacing="0" border="0" role="presentation" width="16"
                                                       style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                                    <tbody>
                                                    <tr
                                                            style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                                        <td
                                                                style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                                        </td>
                                                    </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <!--[if mso | IE]></td><![endif]--><!--[if mso | IE]><td style="width: 296px;vertical-align: middle;" valign="middle"><![endif]-->
                                            <div class="bwf-email-inner-column-wrapper bwf-email-inner-column-wrapper-d7001fd"
                                                 style="font-size: 0px; border-collapse: separate; display: table-cell; vertical-align: middle; mso-border-alt: none; width: 296px;">
                                                <table cellpadding="0" cellspacing="0" border="0"
                                                       style="border-spacing: 0px; border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;"
                                                       role="presentation"
                                                       class="bwf-email-inner-column bwf-email-inner-column-d7001fd" width="100%">
                                                    <tbody>
                                                    <tr
                                                            style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px;">
                                                        <td class="bwf-email-text bwf-email-text-bdc66e9"
                                                            style="border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-spacing: 0px; padding: 0px 0px 8px 0px; word-break: break-word;">
                                                            <div class="bwf-block-text-container"
                                                                 style="text-align: right; font-size: 16px; font-family: arial,helvetica,sans-serif; line-height: 1.3; color: #353030;">
                                                                <p class="bwf-block-text-inner-container"
                                                                   style="margin: 0; mso-line-height-alt: 130%; word-break: break-word; font-size: 16px; font-family: arial,helvetica,sans-serif; line-height: 1.3;">
                                                                <span
                                                                        style="mso-line-height-alt: 130%; font-size: 13px;"
                                                                        class="has-font-size">🚀</span><span
                                                                            style="mso-line-height-alt: 130%; font-size: 14px;"
                                                                            class="has-font-size">&nbsp;<strong><span
                                                                                    style="mso-line-height-alt: 130%; background-color: rgba(0, 0, 0, 0); color: #000000;"
                                                                                    class="has-inline-color">
                                                                            <?php
                                                                            echo wp_kses( __( 'Boost Profits with <br>Funnels &amp; Automations', 'wp-marketing-automations' ), array(
	                                                                            'br' => array(),
                                                                            ) );
                                                                            ?>
                                                                        </span></strong></span><br>
                                                                </p>
                                                            </div>
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