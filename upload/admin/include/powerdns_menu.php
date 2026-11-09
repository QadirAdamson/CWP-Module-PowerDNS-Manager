<script type="text/javascript">
$(document).ready(function() {
    // PowerDNS Manager link positioned as the FIRST item inside DNS Functions submenu (#mn-12-sub)
    if (!$("#mn-powerdns-sub").length) {
        var pdnsSubItem = '<li id="mn-powerdns-sub">'
            + '<a href="index.php?module=powerdns-manager" style="font-weight:bold; color:#2f80ed;">'
            + '<span class="icon16 icomoon-icon-lightning" style="color: #2f80ed !important; margin-right: 5px;"></span>'
            + '<span class="txt">PowerDNS Manager</span>'
            + '</a>'
            + '</li>';

        if ($("#mn-12-sub").length) {
            $("#mn-12-sub").prepend(pdnsSubItem);
        } else if ($("#mn-12").length) {
            $("#mn-12").after(pdnsSubItem);
        } else {
            $("#myList").append(pdnsSubItem);
        }
    }

    // Keep DNS Functions menu expanded and highlighted when on powerdns module
    if (window.location.search.indexOf('module=powerdns-manager') !== -1 || window.location.href.indexOf('module=powerdns-manager') !== -1) {
        var $dnsSub = $("#mn-12-sub");
        var $dnsLi = $("#mn-12");
        var $dnsToggle = $dnsLi.children("a.hasUl, a.notExpand");

        if ($dnsSub.length) {
            $dnsSub.addClass("show").css("display", "block");
        }
        if ($dnsLi.length) {
            $dnsLi.addClass("hasSub highlight-menu active-state");
        }
        if ($dnsToggle.length) {
            $dnsToggle.removeClass("notExpand").addClass("expand active-state");
        }
        $("#mn-powerdns-sub > a").addClass("active");
    }
});
</script>
