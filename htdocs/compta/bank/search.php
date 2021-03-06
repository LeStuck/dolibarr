<?php
/* Copyright (C) 2001-2002  Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2016  Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2010  Regis Houssin        <regis.houssin@capnetworks.com>
 * Copyright (C) 2012       Vinícius Nogueira    <viniciusvgn@gmail.com>
 * Copyright (C) 2014       Florian Henry    	 <florian.henry@open-cooncept.pro>
 * Copyright (C) 2015       Jean-François Ferry	<jfefe@aternatik.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *	\file       htdocs/compta/bank/search.php
 *	\ingroup    banque
 *	\brief      List of bank transactions
 */

require('../../main.inc.php');
require_once DOL_DOCUMENT_ROOT.'/core/lib/bank.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/bankcateg.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';

$langs->load("banks");
$langs->load("categories");
$langs->load("companies");
$langs->load("margins");

// Security check
if ($user->societe_id) $socid=$user->societe_id;
$result=restrictedArea($user,'banque');

$search_ref=GETPOST('search_ref','int');
$description=GETPOST("description",'alpha');
$debit=GETPOST("debit",'alpha');
$credit=GETPOST("credit",'alpha');
$type=GETPOST("type",'alpha');
$account=GETPOST("account",'int');
$bid=GETPOST("bid","int");
$search_dt_start = dol_mktime(0, 0, 0, GETPOST('search_start_dtmonth', 'int'), GETPOST('search_start_dtday', 'int'), GETPOST('search_start_dtyear', 'int'));
$search_dt_end = dol_mktime(0, 0, 0, GETPOST('search_end_dtmonth', 'int'), GETPOST('search_end_dtday', 'int'), GETPOST('search_end_dtyear', 'int'));
$search_thirdparty=GETPOST("thirdparty",'alpha');
$search_req_nb=GETPOST("req_nb",'alpha');

$limit = GETPOST('limit')?GETPOST('limit','int'):$conf->liste_limit;
$sortfield = GETPOST("sortfield",'alpha');
$sortorder = GETPOST("sortorder",'alpha');
$page = GETPOST("page",'int');
if ($page == -1) { $page = 0; }
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;
if (! $sortorder) $sortorder='DESC';
if (! $sortfield) $sortfield='b.dateo';

// Initialize technical object to manage context to save list fields
$contextpage='banktransactionlist';

// Initialize technical object to manage hooks of thirdparties. Note that conf->hooks_modules contains array array
$hookmanager->initHooks(array($contextpage));
$extrafields = new ExtraFields($db);

// fetch optionals attributes and labels
$extralabels = $extrafields->fetch_name_optionals_label('banktransaction');
$search_array_options=$extrafields->getOptionalsFromPost($extralabels,'','search_');

$arrayfields=array(
    'b.ref'=>array('label'=>$langs->trans("Ref"), 'checked'=>1),
    'b.date_commande'=>array('label'=>$langs->trans("OrderDateShort"), 'checked'=>1),
    'b.date_delivery'=>array('label'=>$langs->trans("DateDeliveryPlanned"), 'checked'=>1, 'enabled'=>empty($conf->global->ORDER_DISABLE_DELIVERY_DATE)),
    'b.total_ht'=>array('label'=>$langs->trans("AmountHT"), 'checked'=>1),
    'b.total_vat'=>array('label'=>$langs->trans("AmountVAT"), 'checked'=>0),
    'b.total_ttc'=>array('label'=>$langs->trans("AmountTTC"), 'checked'=>0),
    'b.datec'=>array('label'=>$langs->trans("DateCreation"), 'checked'=>0, 'position'=>500),
    'b.tms'=>array('label'=>$langs->trans("DateModificationShort"), 'checked'=>0, 'position'=>500),
    'b.fk_statut'=>array('label'=>$langs->trans("Status"), 'checked'=>1, 'position'=>1000),
    'b.facture'=>array('label'=>$langs->trans("Billed"), 'checked'=>1, 'position'=>1000, 'enabled'=>(empty($conf->global->WORKFLOW_BILL_ON_SHIPMENT)))
);
// Extra fields
if (is_array($extrafields->attribute_label) && count($extrafields->attribute_label))
{
    foreach($extrafields->attribute_label as $key => $val)
    {
        $arrayfields["ef.".$key]=array('label'=>$extrafields->attribute_label[$key], 'checked'=>$extrafields->attribute_list[$key], 'position'=>$extrafields->attribute_pos[$key], 'enabled'=>$extrafields->attribute_perms[$key]);
    }
}



/*
 * Actions
 */       

if (GETPOST('cancel')) { $action='list'; $massaction=''; }
if (! GETPOST('confirmmassaction') && $massaction != 'presend' && $massaction != 'confirm_presend') { $massaction=''; }

$parameters=array();
$reshook=$hookmanager->executeHooks('doActions',$parameters,$object,$action);    // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');

include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';

if (GETPOST("button_removefilter_x") || GETPOST("button_removefilter.x") || GETPOST("button_removefilter")) // Both test are required to be compatible with all browsers
{
	$description="";
	$type="";
	$debit="";
	$credit="";
	$account="";
	$bid="";
	$search_ref="";
	$search_req_nb='';
	$search_thirdparty='';
	$thirdparty='';
}

if (empty($reshook))
{
    $objectclass='Account';
    $objectlabel='BankTransaction';
    $permtoread = $user->rights->banque->lire;
    $permtodelete = $user->rights->banque->supprimer;
    $uploaddir = $conf->banque->dir_output;
    include DOL_DOCUMENT_ROOT.'/core/actions_massactions.inc.php';
}


/*
 * View
 */

$companystatic=new Societe($db);
$bankaccountstatic=new Account($db);

llxHeader('', $langs->trans("BankTransactions"), '', '', 0, 0, array(), array(), $param);

$form = new Form($db);
$formother = new FormOther($db);

if ($vline) $viewline = $vline;
else $viewline = 50;

$sql = "SELECT b.rowid, b.dateo as do, b.datev as dv, b.amount, b.label, b.rappro, b.num_releve, b.num_chq,";
$sql.= " b.fk_account, b.fk_type,";
$sql.= " ba.rowid as bankid, ba.ref as bankref,";
$sql.= " bu.url_id,";
$sql.= " s.nom, s.name_alias, s.client, s.fournisseur, s.code_client, s.code_fournisseur";
// Add fields from extrafields
foreach ($extrafields->attribute_label as $key => $val) $sql.=($extrafields->attribute_type[$key] != 'separate' ? ",ef.".$key.' as options_'.$key : '');
// Add fields from hooks
$parameters=array();
$reshook=$hookmanager->executeHooks('printFieldListSelect',$parameters);    // Note that $action and $object may have been modified by hook
$sql.=$hookmanager->resPrint;
$sql.= " FROM ";
if ($bid) $sql.= MAIN_DB_PREFIX."bank_class as l,";
$sql.= " ".MAIN_DB_PREFIX."bank_account as ba,";
$sql.= " ".MAIN_DB_PREFIX."bank as b";
if (is_array($extrafields->attribute_label) && count($extrafields->attribute_label)) $sql.= " LEFT JOIN ".MAIN_DB_PREFIX."bank_extrafields as ef on (b.rowid = ef.fk_object)";
$sql.= " LEFT JOIN ".MAIN_DB_PREFIX."bank_url as bu ON bu.fk_bank = b.rowid AND type = 'company'";
$sql.= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON bu.url_id = s.rowid";
$sql.= " WHERE b.fk_account = ba.rowid";
$sql.= " AND ba.entity IN (".getEntity('bank_account', 1).")";
if ($search_ref) $sql.=natural_search("b.rowid", $search_ref);
if ($account > 0) $sql.=" AND b.fk_account = ".$account;
if ($search_req_nb) $sql.= " AND b.num_chq LIKE '%".$db->escape($search_req_nb)."%'";
if ($search_thirdparty) $sql.=" AND s.nom LIKE '%".$db->escape($search_thirdparty)."%'";
if ($bid) $sql.= " AND b.rowid=l.lineid AND l.fk_categ=".$bid;
if (! empty($type)) $sql.= " AND b.fk_type = '".$db->escape($type)."' ";
// Search period criteria
if (dol_strlen($search_dt_start)>0) $sql .= " AND b.dateo >= '" . $db->idate($search_dt_start) . "'";
if (dol_strlen($search_dt_end)>0) $sql .= " AND b.dateo <= '" . $db->idate($search_dt_end) . "'";
// Search criteria amount
$debit = price2num(str_replace('-','',$debit));
$credit = price2num(str_replace('-','',$credit));
if ($debit) $sql.= natural_search('- b.amount', $debit, 1);
if ($credit) $sql.= natural_search('b.amount', $credit, 1);
// Add where from extra fields
foreach ($search_array_options as $key => $val)
{
    $crit=$val;
    $tmpkey=preg_replace('/search_options_/','',$key);
    $typ=$extrafields->attribute_type[$tmpkey];
    $mode=0;
    if (in_array($typ, array('int','double'))) $mode=1;    // Search on a numeric
    if ($val && ( ($crit != '' && ! in_array($typ, array('select'))) || ! empty($crit)))
    {
        $sql .= natural_search('ef.'.$tmpkey, $crit, $mode);
    }
}
// Add where from hooks
$parameters=array();
$reshook=$hookmanager->executeHooks('printFieldListWhere',$parameters);    // Note that $action and $object may have been modified by hook
$sql.=$hookmanager->resPrint;

$sql.= $db->order($sortfield,$sortorder);

$nbtotalofrecords = 0;
if (empty($conf->global->MAIN_DISABLE_FULL_SCANLIST))
{
    $result = $db->query($sql);
    $nbtotalofrecords = $db->num_rows($result);
}

$sql.= $db->plimit($limit+1,$offset);

dol_syslog('compta/bank/search.php::', LOG_DEBUG);
$resql = $db->query($sql);
if ($resql)
{
	$var=True;
	$num = $db->num_rows($resql);
	
	$arrayofselected=is_array($toselect)?$toselect:array();
	
	$param='';
	if (! empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) $param.='&contextpage='.$contextpage;
	if ($limit > 0 && $limit != $conf->liste_limit) $param.='&limit='.$limit;
	if (!empty($search_ref)) $param.='&search_ref='.$search_ref;
	if (!empty($description)) $param.='&description='.$description;
	if (!empty($type)) $param.='&type='.$type;
	if (!empty($debit)) $param.='&debit='.$debit;
	if (!empty($credit)) $param.='&credit='.$credit;
	if (!empty($account)) $param.='&account='.$account;
	if (!empty($bid))  $param.='&bid='.$bid;
	if (dol_strlen($search_dt_start) > 0) $param .= '&search_start_dtmonth=' . GETPOST('search_start_dtmonth', 'int') . '&search_start_dtday=' . GETPOST('search_start_dtday', 'int') . '&search_start_dtyear=' . GETPOST('search_start_dtyear', 'int');
    if (dol_strlen($search_dt_end) > 0)   $param .= '&search_end_dtmonth=' . GETPOST('search_end_dtmonth', 'int') . '&search_end_dtday=' . GETPOST('search_end_dtday', 'int') . '&search_end_dtyear=' . GETPOST('search_end_dtyear', 'int');
    if (GETPOST("req_nb")) $param.='&amp;req_nb='.urlencode(GETPOST("req_nb"));
    if (GETPOST("thirdparty")) $param.='&amp;thirdparty='.urlencode(GETPOST("thirdparty"));
    if ($optioncss != '')       $param.='&optioncss='.$optioncss;
    // Add $param from extra fields
    foreach ($search_array_options as $key => $val)
    {
        $crit=$val;
        $tmpkey=preg_replace('/search_options_/','',$key);
        if ($val != '') $param.='&search_options_'.$tmpkey.'='.urlencode($val);
    }
    
    // List of mass actions available
    $arrayofmassactions =  array(
        'presend'=>$langs->trans("SendByMail"),
        'builddoc'=>$langs->trans("PDFMerge"),
    );
    if ($user->rights->commande->supprimer) $arrayofmassactions['delete']=$langs->trans("Delete");
    if ($massaction == 'presend') $arrayofmassactions=array();
    $massactionbutton=$form->selectMassAction('', $arrayofmassactions);
    
    // Lines of title fields
	print '<form method="post" action="'.$_SERVER["PHP_SELF"].'" name="search_form">'."\n";
	if ($optioncss != '') print '<input type="hidden" name="optioncss" value="'.$optioncss.'">';
	print '<input type="hidden" name="token" value="'.$_SESSION['newtoken'].'">';
	print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
	print '<input type="hidden" name="action" value="search">';
	print '<input type="hidden" name="view" value="'.dol_escape_htmltag($view).'">';
	print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
	print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
	if (! empty($_REQUEST['bid'])) print '<input type="hidden" name="bid" value="'.$_REQUEST["bid"].'">';
	
	$i = 0;
	
	// Title
	$bankcateg=new BankCateg($db);
	if (GETPOST("bid"))
	{
		$result=$bankcateg->fetch(GETPOST("bid"));
		print_barre_liste($langs->trans("BankTransactionForCategory",$bankcateg->label).' '.($socid?' '.$soc->name:''), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, 'title_bank.png', 0, '', '', $limit);
	}
	else
	{
		print_barre_liste($langs->trans("BankTransactions"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, 'title_bank.png', 0, '', '', $limit);
	}
	
	$moreforfilter = '';
	
	$moreforfilter.='<div class="divsearchfield">';
	$moreforfilter .= $langs->trans('Period') . ' ('.$langs->trans('DateOperationShort').') : ';
	$moreforfilter .= '<div class="nowrap inline-block">'.$langs->trans('DateStart') . ' ';
	$moreforfilter .= $form->select_date($search_dt_start, 'search_start_dt', 0, 0, 1, "search_form", 1, 0, 1).'</div>';
	$moreforfilter .= ' - ';
	$moreforfilter .= '<div class="nowrap inline-block">'.$langs->trans('DateEnd') . ' ' . $form->select_date($search_dt_end, 'search_end_dt', 0, 0, 1, "search_form", 1, 0, 1).'</div>';
	$moreforfilter .= '</div>';
	
	$parameters=array();
	$reshook=$hookmanager->executeHooks('printFieldPreListTitle',$parameters);    // Note that $action and $object may have been modified by hook
	if (empty($reshook)) $moreforfilter .= $hookmanager->resPrint;
	else $moreforfilter = $hookmanager->resPrint;
	
	if ($moreforfilter) 
	{
		print '<div class="liste_titre liste_titre_bydiv centpercent">';
		print $moreforfilter;
		print '</div>'."\n";
	}
	
    $varpage=empty($contextpage)?$_SERVER["PHP_SELF"]:$contextpage;
    $selectedfields=$form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $varpage);	// This also change content of $arrayfields
	
	print '<table class="tagtable liste'.($moreforfilter?" listwithfilterbefore":"").'">'."\n";
	
	// Fields title
	print '<tr class="liste_titre">';
	print_liste_field_titre($langs->trans('Ref'),$_SERVER['PHP_SELF'],'b.rowid','',$param,'',$sortfield,$sortorder);
	print_liste_field_titre($langs->trans('DateOperationShort'),$_SERVER['PHP_SELF'],'b.dateo','',$param,'align="center"',$sortfield,$sortorder);
    print_liste_field_titre($langs->trans('Value'),$_SERVER['PHP_SELF'],'b.datev','',$param,'align="center"',$sortfield,$sortorder);
	print_liste_field_titre($langs->trans("Type"),$_SERVER['PHP_SELF'],'','',$param,'align="center"',$sortfield,$sortorder);
    print_liste_field_titre($langs->trans("Numero"),$_SERVER['PHP_SELF'],'b.num_releve','',$param,'align="center"',$sortfield,$sortorder);
	print_liste_field_titre($langs->trans("Description"),$_SERVER['PHP_SELF'],'','',$param,'',$sortfield,$sortorder);
	print_liste_field_titre($langs->trans("ThirdParty"),$_SERVER['PHP_SELF'],'bu.label','',$param,'',$sortfield,$sortorder);
	print_liste_field_titre($langs->trans("Debit"),$_SERVER['PHP_SELF'],'b.amount','',$param,'align="right"',$sortfield,$sortorder);
	print_liste_field_titre($langs->trans("Credit"),$_SERVER['PHP_SELF'],'b.amount','',$param,'align="right"',$sortfield,$sortorder);
	print_liste_field_titre($langs->trans("Account"),$_SERVER['PHP_SELF'],'ba.ref','',$param,'align="right"',$sortfield,$sortorder);
	print_liste_field_titre('');
	print "</tr>\n";

	print '<tr class="liste_titre">';
	print '<td class="liste_titre">';
	print '<input type="text" class="flat" name="search_ref" size="4" value="'.dol_escape_htmltag($search_ref).'">';
	print '</td>';
    print '<td class="liste_titre">&nbsp;</td>';
    print '<td class="liste_titre">&nbsp;</td>';
    print '<td class="liste_titre" align="center">';
    $form->select_types_paiements(empty($type)?'':$type, 'type', '', 2, 0, 1);
    print '</td>';
    // Numero
    print '<td class="liste_titre" align="center"><input type="text" class="flat" name="req_nb" value="'.dol_escape_htmltag($search_req_nb).'" size="2"></td>';
    print '<td class="liste_titre">';
	print '<input type="text" class="flat" name="description" size="10" value="'.dol_escape_htmltag($description).'">';
	print '</td>';
    print '<td class="liste_titre"><input type="text" class="flat" name="thirdparty" value="'.dol_escape_htmltag($search_thirdparty).'" size="10"></td>';
	print '<td class="liste_titre" align="right">';
	print '<input type="text" class="flat" name="debit" size="4" value="'.dol_escape_htmltag($debit).'">';
	print '</td>';
	print '<td class="liste_titre" align="right">';
	print '<input type="text" class="flat" name="credit" size="4" value="'.dol_escape_htmltag($credit).'">';
	print '</td>';
	print '<td align="right">';
	$form->select_comptes($account,'account',0,'',1);
	print '</td>';
    print '<td class="liste_titre" align="right">';
    $searchpitco=$form->showFilterAndCheckAddButtons(0);
    print $searchpitco;
    print '</td>';
	print "</tr>\n";

    // Loop on each record
    $total_debit=0;
    $total_credit=0;
    while ($i < min($num,$limit)) {
        $objp = $db->fetch_object($resql);
        $printline=false;
        //Search Description
        if ($description) {
            preg_match('/\((.+)\)/i',$objp->label,$reg); // Si texte entoure de parenthese on tente recherche de traduction
            if ($reg[1]) {
                if ($langs->transnoentities($reg[1])==$description) {
                    $printline=true;
                }
            } elseif ($objp->label==$description) {
                $printline=true;
            }
        } else {
            $printline=true;
        }

        if ($printline) {
            $var=!$var;

            print "<tr ".$bc[$var].">";

            // Ref
            print '<td align="left" class="nowrap">';
            print "<a href=\"ligne.php?rowid=".$objp->rowid.'">'.img_object($langs->trans("ShowPayment").': '.$objp->rowid, 'payment', 'class="classfortooltip"').' '.$objp->rowid."</a> &nbsp; ";
            print '</td>';

            // Date ope
            print '<td align="center" class="nowrap">'.dol_print_date($db->jdate($objp->do),"day")."</td>\n";

	        // Date value
	        print '<td align="center" class="nowrap">'.dol_print_date($db->jdate($objp->dv),"day")."</td>\n";

	        // Payment type
	        print '<td align="center" class="nowrap">';
	        $labeltype=($langs->trans("PaymentTypeShort".$objp->fk_type)!="PaymentTypeShort".$objp->fk_type)?$langs->trans("PaymentTypeShort".$objp->fk_type):$langs->getLabelFromKey($db,$objp->fk_type,'c_paiement','code','libelle');
	        if ($labeltype == 'SOLD') print '&nbsp;'; //$langs->trans("InitialBankBalance");
	        else print $labeltype;
	        print "</td>\n";

	        // Num
	        print '<td class="nowrap" align="center">'.($objp->num_chq?$objp->num_chq:"")."</td>\n";

	        // Description
			print "<td>";

			print "<a href=\"ligne.php?rowid=".$objp->rowid."&amp;account=".$objp->fk_account."\">";
			$reg=array();
			preg_match('/\((.+)\)/i',$objp->label,$reg);	// Si texte entoure de parenthee on tente recherche de traduction
			if ($reg[1] && $langs->trans($reg[1])!=$reg[1]) print $langs->trans($reg[1]);
			else print dol_trunc($objp->label,40);
			print "</a>&nbsp;";

			print '</td>';

			// Third party
			print "<td>";
			if ($objp->url_id)
			{
				$companystatic->id=$objp->url_id;
				$companystatic->name=$objp->nom;
				$companystatic->name_alias=$objp->name_alias;
				$companystatic->client=$objp->client;
				$companystatic->fournisseur=$objp->fournisseur;
				$companystatic->code_client=$objp->code_client;
				$companystatic->code_fournisseur=$objp->code_fournisseur;
				print $companystatic->getNomUrl(1);
			}
			else
			{
				print '&nbsp;';
			}
			print '</td>';

			// Debit/Credit
			if ($objp->amount < 0)
			{
				print '<td align="right">'.price($objp->amount * -1)."</td><td>&nbsp;</td>\n";
				$total_debit+=$objp->amount;
			}
			else
			{
				print "<td>&nbsp;</td><td align=\"right\">".price($objp->amount)."</td>\n";
				$total_credit+=$objp->amount;
			}

			// Bank account
			print '<td align="right" class="nowrap">';
			$bankaccountstatic->id=$objp->bankid;
			$bankaccountstatic->label=$objp->bankref;
			print $bankaccountstatic->getNomUrl(1);
			print "</td>\n";
			
			print '<td></td>';
			
			print "</tr>";
		}
		$i++;
	}
	if ($num>0) {
		print '<tr  class="liste_total">';
		print '<td>' . $langs->trans('Total') . '</td>';
		print '<td colspan="6"></td>';
		print '<td  align="right">' . price($total_debit * - 1) . '</td>';
		print '<td  align="right">' . price($total_credit) . '</td>';
		print '<td></td>';
		print '<td></td>';
		print '</tr>';
	}

	print "</table>";
    print '</form>';
	$db->free($resql);
}
else
{
	dol_print_error($db);
}

// If no data to display after a search
if ($_POST["action"] == "search" && ! $num)
{
	print '<div class="opacitymedium">'.$langs->trans("NoRecordFound").'</div>';
}

llxFooter();

$db->close();
