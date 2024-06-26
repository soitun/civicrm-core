<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
 <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
 <title></title>
</head>
<body>

{capture assign=headerStyle}colspan="2" style="text-align: left; padding: 4px; border-bottom: 1px solid #999; background-color: #eee;"{/capture}
{capture assign=labelStyle}style="padding: 4px; border-bottom: 1px solid #999; background-color: #f7f7f7;"{/capture}
{capture assign=valueStyle}style="padding: 4px; border-bottom: 1px solid #999;"{/capture}

  <!-- BEGIN HEADER -->
    {* To modify content in this section, you can edit the Custom Token named "Message Header". See also: https://docs.civicrm.org/user/en/latest/email/message-templates/#modifying-system-workflow-message-templates *}
    {site.message_header}
  <!-- END HEADER -->

  <!-- BEGIN CONTENT -->

  <table id="crm-event_receipt" style="font-family: Arial, Verdana, sans-serif; text-align: left; width:100%; max-width:700px; padding:0; margin:0; border:0px;">
  <tr>
   <td>
    <p>{ts}A contribution / membership signup was made on behalf of the organization listed below.{/ts}</p>
    <p>{ts}The information provided matched multiple existing database records based on the configured Duplicate Matching Rules for your site.{/ts}</p>
   </td>
  </tr>
  <tr>
   <td>
    <table style="border: 1px solid #999; margin: 1em 0em 1em; border-collapse: collapse; width:100%;">
     <tr>
      <td {$labelStyle}>
       {ts}Organization Name{/ts}
      </td>
      <td {$valueStyle}>
       {$onBehalfName}
      </td>
     </tr>
     <tr>
      <td {$labelStyle}>
       {ts}Organization Email{/ts}
      </td>
      <td {$valueStyle}>
       {$onBehalfEmail}
      </td>
     </tr>
     <tr>
      <td {$labelStyle}>
       {ts}Organization Contact ID{/ts}
      </td>
      <td {$valueStyle}>
       {$onBehalfID}
      </td>
     </tr>
    </table>
   </td>
  </tr>
  <tr>
   <td>
    <p>{ts}If you think this may be a duplicate contact which should be merged with an existing record - Go to "Contacts >> Manage Duplicates". Use the strict rule for Organizations to find the potential duplicates and merge them if appropriate.{/ts}</p>
   </td>
  </tr>
  {if $receiptMessage}
   <tr>
    <td>
     <table style="border: 1px solid #999; margin: 1em 0em 1em; border-collapse: collapse; width:100%;">
      <tr>
       <th {$headerStyle}>
        {ts}Copy of Contribution Receipt{/ts}
       </th>
      </tr>
      <tr>
       <td colspan="2" {$valueStyle}>
        {* FIXME: the below is most probably not HTML-ised *}
        {$receiptMessage}
       </td>
      </tr>
     </table>
    </td>
   </tr>
  {/if}
 </table>
</body>
</html>
