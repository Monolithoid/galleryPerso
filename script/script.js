/*************************************************
    Fonction dimensions de la fenètre
**************************************************/
function getWindowHeight() {
    var h = 0;
    if (typeof(window.innerHeight) == 'number') { // Netscape
        h = window.innerHeight;
    } else if (document.documentElement && document.documentElement.clientHeight) {
        h = document.documentElement.clientHeight;
    } else if (document.body && document.body.offsetHeight) { //client
        h = document.body.offsetHeight;
    }
    return h;
}

function getWindowWidth() {
    var w = 0;
    if (typeof(window.innerWidth) == 'number') { // Netscape
        w = window.innerWidth;
    } else if (document.documentElement && document.documentElement.clientWidth) {
        w = document.documentElement.clientWidth;
    } else if (document.body && document.body.offsetWidth) { //client
        w = document.body.offsetWidth;
    }
    return w;
}

/*************************************************
    Fonction redimensionnement
**************************************************/
function UpdateResolution() {
	if (document.form_Gallery.resolution.value != (getWindowWidth() + 'x' + getWindowHeight()))
	{
		document.form_Gallery.resolution.value = getWindowWidth() + 'x' + getWindowHeight();
		document.form_Gallery.submit();
	}
}

var currentangle = 0;

function set_elem(elem, top, left, width, height, zIndex)
{
	elem.style.top = top;
	elem.style.left = left;
	elem.style.width = width;
	elem.style.height = height;
	elem.style.zIndex = zIndex;
}

function moveImageTools(toppos, leftpos, width, height, tools)
{
	var snapshot = window.document.getElementById("snapshot");
	var right = window.document.getElementById("right");
	var left = window.document.getElementById("left");
	var next = window.document.getElementById("next");
	var previous = window.document.getElementById("previous");
	var close = window.document.getElementById("close");
	var zoom = window.document.getElementById("zoom");
	
	var legend = window.document.getElementById("legend");
	
	// o           x
	// <  Snapshot >
	//   <)  |  (>
	
	if ( tools === undefined )
		tools = 0xFFFF;
	
	if (tools & 0x0080)
	{
		set_elem(snapshot, toppos - 10, leftpos - 10, width + 20, height + 20, 4);
	}
	else
	{
		if ((tools & 0x0100) == 0)
		{
			set_elem(snapshot, 0, 0, 0, 0, 0);
		}
	}
	
	if (tools & 0x0001)
	{
		set_elem(right, toppos + height - 48, leftpos + width / 2, width / 2, 48, 5);
	}
	else
	{
		set_elem(right, 0, 0, 0, 0, 0);
	}

	if (tools & 0x0002)
	{
		set_elem(left, toppos + height - 48, leftpos, width / 2, 48, 5);
	}
	else
	{
		set_elem(left, 0, 0, 0, 0, 0);
	}
	
	if (tools & 0x0004)
	{
		set_elem(next, toppos + 48, leftpos + width - 48, 48, height - 2*48, 5);
	}
	else
	{
		set_elem(next, 0, 0, 0, 0, 0);
	}
	
	if (tools & 0x0008)
	{
		set_elem(previous, toppos + 48, leftpos, 48, height - 2*48, 5);
	}
	else
	{
		set_elem(previous, 0, 0, 0, 0, 0);
	}
	
	if (tools & 0x0010)
	{
		set_elem(close, toppos, leftpos + width - 48, 48, 48, 5);
	}
	else
	{
		set_elem(close, 0, 0, 0, 0, 0);
	}
	
	if (tools & 0x0020)
	{
		set_elem(zoom, toppos, leftpos, 48, 48, 5);
	}
	else
	{
		set_elem(zoom, 0, 0, 0, 0, 0);
	}
	
	if (tools & 0x0040)
	{
		set_elem(legend, toppos, leftpos + 48, width - (2*48), 100, 5);
	}
	else
	{
		set_elem(legend, 0, 0, 0, 0, 0);
	}
}


function DisplaySnapshot(media_)//, width, height) 
{
	var snapshot = window.document.getElementById("snapshot");
	var right = window.document.getElementById("right");
	var left = window.document.getElementById("left");
	var next = window.document.getElementById("next");
	var previous = window.document.getElementById("previous");
	var close = window.document.getElementById("close");
	var zoom = window.document.getElementById("zoom");
	var legend = window.document.getElementById("legend");
	
	viewer = '';
	
	width = 0;
	height = 0;
	snapshot.innerHTML = '';
	zoom.innerHTML = '';
	legend.innerHTML = '';

	tools = 0x0000;

			
	if (media_ == null)
	{
		moveImageTools(0,0,0,0,tools);
	}
	
	if (media_ != null)
	{
		width = media_.width;
		height = media_.height;
		
		if ((media_.type.indexOf('video') != -1)
			|| (media_.type.indexOf('stream') != -1) )
		{
			//viewer = '<center><object id="img_snapshot" type="application/x-shockwave-flash" data="http://flv-player.net/medias/player_flv_maxi.swf" width="' + media_.width + '" height="' //+ media_.height + '" >'
			//			+ '<param name="movie" value="http://flv-player.net/medias/player_flv_maxi.swf" />'
			//			+ '<param name="allowFullScreen" value="false" />'
			//			+ '<param name="flashvars" value="flv=http://' + window.location.hostname + '/' + media_.middlesrc + '&amp;width=' + media_.width + '&amp;height=' + media_.height + '&amp;autoplay=0&amp;autoload=1&amp;showvolume=1&amp;showfullscreen=0&amp;showiconplay=1&amp;showloading=always" />' //;startimage='+media_.middlesrc+'" />'
			//			+ '<param name="wmode" value="transparent" />'
			//			+ '</object></center>';
						
			//viewer = '<center><video id="img_snapshot" width="'+(media_.width>640?640:media_.width)+'" height="'+(media_.height>480?480:media_.height)+'" controls autoplay poster="/img/play.jpg">';
			viewer = '<center><video id="img_snapshot" width="'+(media_.width)+'" height="'+(media_.height)+'" controls autoplay poster="' + (media_.middlesrc || 'img/play.jpg') + '">';
			viewer += '<source src="'+media_.src+'" >';//type="'+media_.type+'"
			viewer += 'Your browser does not support the video tag.';
			viewer += '</video></center>';
						
			tools = 0x00FC;
		}
		else if (media_.type.indexOf('image') != -1)
		{
			viewer = '<center><img id="img_snapshot" Src="' + media_.middlesrc + '" ></center>';
			tools = 0x00FF;
		}
		else
		{
			alert("else mime type");
		}
		
		toppos = getWindowHeight() / 2 - height / 2;
		leftpos = getWindowWidth() / 2 - width / 2;
		
		moveImageTools(toppos, leftpos, width, height, tools);
		
		legend.innerHTML = media_.legendtext;
		snapshot.innerHTML = viewer;
				
		currentangle = 0;
		$('#snapshot').rotate({angle:currentangle});
                currentimage = findImageNameInList(media_.name);
		
	}
}

var currentimage = 1;

function step(value, sens, max)
{
	if (sens > 0)
	{
		if (value == max)
		{
			value = 1;
		}
		else
		{
			value++;
		}
	}
	else if (sens < 0)
	{
		if (value == 1)
		{
			value = max;
		}
		else
		{
			value--;
		}
	}
	else
	{
		value = 0;
	}
	
	return value;
}

function findImageNameInList(mediaName)
{
        var index;

        for(index = 1; index <= NbMedia; index++)
        {
                if (Medias[index].name == mediaName)
                {
                        break;
                }
        }

        return index;
}

function imagerotation(angle)
{
	$('#snapshot').rotate({
		animateTo: currentangle+angle,
		easing: $.easing.easeInOutElastic
		});
		
	currentangle += angle;
	
	if ((currentangle % 180) == 0)
	{
		width = $('#img_snapshot').width();
		height = $('#img_snapshot').height();
	}
	else
	{
		width = $('#img_snapshot').height();
		height = $('#img_snapshot').width();
	}
	
	toppos = getWindowHeight() / 2 - height / 2;
	leftpos = getWindowWidth() / 2 - width / 2;

	moveImageTools(toppos, leftpos, width, height, 0x017F);
}

function imagechange(sens)
{
	currentimage = step(currentimage, sens, NbMedia);
	
	DisplaySnapshot(Medias[currentimage]);
}

window.onresize = UpdateResolution;
window.onload = UpdateResolution;

function media(name, src, middlesrc, legendtext, width, height, type)
{
	this.name = name;
	this.src = src;
	this.middlesrc = middlesrc;
	this.legendtext = legendtext;
	this.width = width;
	this.height = height;
	this.type = type;
}
