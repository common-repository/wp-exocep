(function() {
    var TYPES = ['bar', 'cafe', 'liquor_store', 'lodging', 'meal_delivery', 'meal_takeaway', 'night_club', 'restaurant', 'shopping_mall', 'store'];

    function getDomain(url) {
        var a = document.createElement('a');
        a.href = url;
        return a.hostname;
    }

    function getOpening(openings) {
        var opening = openings[(new Date().getDay() + 6) % 7];
        return opening.substr(opening.indexOf(':') + 1).trim();
    }

    function initExomap() {
        var els = document.querySelectorAll('[data-exomap]');
        els.forEach(function(el) {
            var timer;
            var families = JSON.parse(el.dataset.exomapFamilies) || [];
            var markers = JSON.parse(el.dataset.exomapMarkers) || [];
            var filter = JSON.parse(el.dataset.exomapFilter);
            var map = L.map(el, {
                center: [0, 0],
                zoom: 8
            });

            var tile = L.tileLayer('https://api.mapbox.com/styles/v1/naonedit/ckaeilqya0ewp1io6k5h7cc88/tiles/256/{z}/{x}/{y}@2x?access_token={accessToken}', {
                maxZoom: 18,
                id: 'naonedit.l175n140',
                accessToken: 'pk.eyJ1IjoibmFvbmVkaXQiLCJhIjoiY1ZDVmt2ayJ9.m9Q4k24UNYh3bNw3YauzYg'
            });
            map.addLayer(tile);

            var filters = {};
            families.forEach(function(family) {
               filters[family.name] = true;
            });

            var cluster = new L.MarkerClusterGroup({
                showCoverageOnHover: false
            });

            function buildMarkers() {
                cluster.clearLayers();
                markers.forEach(function(marker) {
                    if (!filters[marker.family]) {
                        return;
                    }
                    var m = L.marker(new L.LatLng(marker.lat, marker.lng));

                    m.bindPopup(function(layer) {
                        if (timer) {
                            clearTimeout(timer);
                        }
                        timer = setTimeout(function() {
                            var service = new google.maps.places.PlacesService(document.createElement('div'));
                            service.textSearch({query: marker.name + ' ' + (marker.address || ''), location: new google.maps.LatLng(marker.lat, marker.lng), rankBy: google.maps.places.RankBy.DISTANCE}, function(places) {
                                if (!places.some(function(place) {
                                        return place.types.some(function(type) {
                                            if (TYPES.indexOf(type) >= 0) {
                                                service.getDetails(place, function(details) {
                                                    layer.setPopupContent(
                                                        '<div class="exomap-popup">' +
                                                        '   <div class="exomap-title">' + details.name + '</div>' +
                                                        '   <div class="exomap-address">' + details.adr_address + '</div>' +
                                                        (details.opening_hours && details.opening_hours.weekday_text?'<div class="exomap-openings"><span class="exomap-label">Horaires</span>' + getOpening(details.opening_hours.weekday_text) + '</a></div>':'') +
                                                        (details.international_phone_number?'<div class="exomap-phone"><span class="exomap-label">Téléphone</span><a href="tel:' + details.international_phone_number + '">' + details.international_phone_number + '</a></div>':'') +
                                                        (details.website?'<div class="exomap-website"><span class="exomap-label">Site</span><a target="_blank" href="' + details.website + '">' + getDomain(details.website) + '</a></div>':'') +
                                                        '   <a class="exomap-goto" target="_blank" href="https://maps.apple.com/maps?daddr=' + details.geometry.location.lat() + ',' + details.geometry.location.lng() + '">Y aller</a>' +
                                                        '</div>'
                                                    );
                                                });
                                                return true;
                                            }
                                            return false;
                                        });
                                    })) {
                                    //Default content
                                    layer.setPopupContent(
                                        '<div class="exomap-popup">' +
                                        '   <div class="exomap-title">' + marker.name + '</div>' +
                                        '   <div class="exomap-address">' +
                                        '       <div>' + (marker.address || '').replace(/\\n/g, '<br/>').replace(/\\,/g, ',') + '</div>' +
                                        '       <div>' + marker.zipcode + ' ' + marker.city + '</div>' +
                                        '   </div>' +
                                        '</div>'
                                    );
                                }
                            });
                        }, 300);
                        return '<div class="exomap-popup"><div class="exomap-spinner"></div></div>';
                    }, {autoPanPaddingTopLeft:L.point(40, 5)});
                    cluster.addLayer(m);
                });
                var bounds = cluster.getBounds();

                if(bounds.isValid()) {
                    map.fitBounds(bounds, {padding: [40, 40]});
                }
            }
            map.addLayer(cluster);

            if (filter) {
                var filterControl = L.Control.extend({
                    options: {
                        position: 'bottomleft'
                    },
                    onAdd: function (map) {
                        var container = L.DomUtil.create('form', 'exomap-filter leaflet-bar leaflet-control leaflet-control-custom');
                        var content = '';
                        families.forEach(function(family) {
                            content += '<label><input name="family" type="checkbox" value="' + family.name + '" checked/><span>' + family.label + '</span></label>';
                        });
                        container.innerHTML = content;
                        for (var i = 0; i < container.elements.length; i++) {
                            container.elements[i].onchange = function() {
                                for (var j = 0; j < container.elements.length; j++) {
                                    filters[container.elements[j].value] = container.elements[j].checked;
                                }
                                buildMarkers();
                            }
                        }
                        return container;
                    }
                });
                map.addControl(new filterControl());
            }

            var locateControl = L.Control.extend({
                options: {
                    position: 'topleft'
                },
                onAdd: function (map) {
                    var container = L.DomUtil.create('div', 'exomap-locate leaflet-bar leaflet-control');
                    var link = L.DomUtil.create('a', 'leaflet-bar-part leaflet-bar-part-single', container);
                    link.href = '#';
                    var icon = L.DomUtil.create('span', 'exomap-locate--icon dashicons dashicons-location', link);
                    var state = 'inactive';
                    var marker = null;
                    var circle = null;

                    var layer = new L.LayerGroup();
                    layer.addTo(map);

                    map.on('locationfound', function(e) {
                        L.DomUtil.removeClass(icon, 'searching');
                        L.DomUtil.addClass(icon, 'active');
                        state = 'active';
                        if (!circle) {
                            circle = L.circle(e.latlng, e.accuracy, {color: '#136AEC', fillColor: '#136AEC', fillOpacity: 0.15, weight: 2, opacity: 0.5}).addTo(layer);
                            marker = new L.CircleMarker(e.latlng, {color: '#136AEC', fillColor: '#2A93EE', fillOpacity: 0.7, weight: 2, opacity: 0.9, radius: 5}).addTo(layer);
                        }
                        else {
                            circle.setLatLng(e.latlng).setRadius(e.accuracy);
                            marker.setLatLng(e.latlng);
                        }
                    });

                    map.on('locationerror', function(e) {
                        L.DomUtil.removeClass(icon, 'searching');
                        L.DomUtil.removeClass(icon, 'active');
                        state = 'inactive';
                    });

                    var onClick = function() {
                        if (state === 'inactive' || state === 'active') {
                            state = 'searching';
                            L.DomUtil.removeClass(icon, 'active');
                            L.DomUtil.addClass(icon, 'searching');
                            map.locate({setView: true, maxZoom: 15});
                        }
                    };

                    L.DomEvent
                        .on(link, 'click', L.DomEvent.stopPropagation)
                        .on(link, 'click', L.DomEvent.preventDefault)
                        .on(link, 'click', onClick, this)
                        .on(link, 'dblclick', L.DomEvent.stopPropagation);

                    return container;
                }
            });

            map.addControl(new locateControl());

            buildMarkers();
        });
    }

    function initExolead() {
        var els = document.querySelectorAll('.exolead');
        els.forEach(function (el) {

            var form = el.querySelector('.exolead--form');
            var categories = el.querySelectorAll('.exolead--category-input');
            var email = el.querySelector('.exolead--email');
            var button = el.querySelector('.exolead--button');
            var message = el.querySelector('.exolead--message');

            function validate() {
                var emailValid = /^[_A-Za-z0-9-\+]+(\.[_A-Za-z0-9-+]+)*@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*(\.[A-Za-z]{2,})$/.test(email.value);
                var categValid = false;
                categories.forEach(function (category) {
                    categValid = categValid || category.checked;
                });
                button.disabled = !emailValid || !categValid;
            }
            categories.forEach(function(category) {
               category.addEventListener('input', validate);
            });
            email.addEventListener('input', validate);

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                el.classList.add('pending');
                var data = new FormData(form);
                data.append("_ajax_nonce", ajax_var.nonce);
                data.append('action', 'exocep_follow');
                var request = new XMLHttpRequest();
                request.onreadystatechange = function() {
                    if (request.readyState === 4) {
                        var response = JSON.parse(request.response);
                        if (response.error) {
                            el.classList.add('error');
                            message.textContent = response.error;
                        }
                        else {
                            el.classList.add('success');
                            message.textContent = response.message;
                        }
                    }
                };
                request.open("POST", ajax_var.url);
                request.send(data);
            });
        });
    }

    window.addEventListener("DOMContentLoaded", function() {
        initExomap();
        initExolead();
    });
})();
