$(document).ready(function() {
    if ($('#hitsgbv').hasClass('active')) getNumberOfPrimoMatches();
    if ($('#hitsprimo').hasClass('active')) getNumberOfGbvMatches();
});

function getNumberOfPrimoMatches() {
    $.ajax({
        dataType: 'json',
        url: path + '/AJAX/JSON?lookfor='+get('lookfor'),
        data: {"idx":"primo", "method":"getNumberOfMatches"},
        success: function(resp) {
            if(resp.status == 'OK') {
                $('#hitsprimo').empty().append('('+Trenner(resp.data.matches)+')');
            }
        }
    });
}

function getNumberOfGbvMatches() {
    $.ajax({
        dataType: 'json',
        url: path + '/AJAX/JSON?lookfor='+get('lookfor'),
        data: {"idx":"gbv", "method":"getNumberOfMatches"},
        success: function(resp) {
            if(resp.status == 'OK') {
                $('#hitsgbv').empty().append('('+Trenner(resp.data.matches)+')');
            }
        }
    });
}

function get(name){
   if(name=(new RegExp('[?&]'+encodeURIComponent(name)+'=([^&]*)')).exec(location.search)) {
      return decodeURIComponent(name[1]);
   }
}

function Trenner(number) {
    // Info: Die '' sind zwei Hochkommas
    number = '' + number;
    if (number.length > 3) {
        var mod = number.length % 3;
        var output = (mod > 0 ? (number.substring(0,mod)) : '');
        for (i=0 ; i < Math.floor(number.length / 3); i++) {
            if ((mod == 0) && (i == 0))
                output += number.substring(mod+ 3 * i, mod + 3 * i + 3);
            else
                // hier wird das Trennzeichen festgelegt mit ','
                output+= ',' + number.substring(mod + 3 * i, mod + 3 * i + 3);
        }
        return (output);
    }
    else return number;
}