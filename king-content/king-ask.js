/*



	File: king-content/king-ask.js
	Version: See define()s at top of king-include/king-base.php
	Description: Javascript for ask page and question editing, including tag auto-completion


	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	More about this license: LICENCE.html
*/

function qa_title_change(value) {
  qa_ajax_post("asktitle", { title: value }, function (lines) {
    if (lines[0] == "1") {
      if (lines[1].length) {
        qa_tags_examples = lines[1];
        qa_tag_hints(true);
      }

      if (lines.length > 2) {
        var simelem = document.getElementById("similar");
        if (simelem) simelem.innerHTML = lines.slice(2).join("\n");
      }
    } else if (lines[0] == "0") alert(lines[1]);
    else qa_ajax_error();
  });

  qa_show_waiting_after(document.getElementById("similar"), true);
}

function qa_html_unescape(html) {
  return html
    .replace(/&amp;/g, "&")
    .replace(/&quot;/g, '"')
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">");
}

function qa_html_escape(text) {
  return text
    .replace(/&/g, "&amp;")
    .replace(/"/g, "&quot;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

function qa_tag_click(link) {
  var elem = document.getElementById("tags");
  var parts = qa_tag_typed_parts(elem);

  // removes any HTML tags and ampersand
  var tag = qa_html_unescape(link.innerHTML.replace(/<[^>]*>/g, ""));

  var separator = qa_tag_onlycomma ? ", " : " ";

  // replace if matches typed, otherwise append
  var newvalue =
    parts.typed && tag.toLowerCase().indexOf(parts.typed.toLowerCase()) >= 0
      ? parts.before + separator + tag + separator + parts.after + separator
      : elem.value + separator + tag + separator;

  // sanitize and set value
  if (qa_tag_onlycomma)
    elem.value = newvalue
      .replace(/[\s,]*,[\s,]*/g, ", ")
      .replace(/^[\s,]+/g, "");
  else elem.value = newvalue.replace(/[\s,]+/g, " ").replace(/^[\s,]+/g, "");

  elem.focus();
  qa_tag_hints();

  return false;
}

function qa_tag_hints(skipcomplete) {
  var elem = document.getElementById("tags");
  var html = "";
  var completed = false;

  // first try to auto-complete
  if (qa_tags_complete && !skipcomplete) {
    var parts = qa_tag_typed_parts(elem);

    if (parts.typed) {
      html = qa_tags_to_html(
        qa_html_unescape(qa_tags_examples + "," + qa_tags_complete).split(","),
        parts.typed.toLowerCase()
      );
      completed = html ? true : false;
    }
  }

  // otherwise show examples
  if (qa_tags_examples && !completed)
    html = qa_tags_to_html(qa_html_unescape(qa_tags_examples).split(","), null);

  // set title visiblity and hint list
  document.getElementById("tag_examples_title").style.display =
    html && !completed ? "" : "none";
  document.getElementById("tag_complete_title").style.display =
    html && completed ? "" : "none";
  document.getElementById("tag_hints").innerHTML = html;
}

function qa_tags_to_html(tags, matchlc) {
  var html = "";
  var added = 0;
  var tagseen = {};

  for (var i = 0; i < tags.length; i++) {
    var tag = tags[i];
    var taglc = tag.toLowerCase();

    if (!tagseen[taglc]) {
      tagseen[taglc] = true;

      if (!matchlc || taglc.indexOf(matchlc) >= 0) {
        // match if necessary
        if (matchlc) {
          // if matching, show appropriate part in bold
          var matchstart = taglc.indexOf(matchlc);
          var matchend = matchstart + matchlc.length;
          inner =
            '<span style="font-weight:normal;">' +
            qa_html_escape(tag.substring(0, matchstart)) +
            "<b>" +
            qa_html_escape(tag.substring(matchstart, matchend)) +
            "</b>" +
            qa_html_escape(tag.substring(matchend)) +
            "</span>";
        } // otherwise show as-is
        else inner = qa_html_escape(tag);

        html +=
          qa_tag_template.replace(/\^/g, inner.replace("$", "$$$$")) + " "; // replace ^ in template, escape $s

        if (++added >= qa_tags_max) break;
      }
    }
  }

  return html;
}

function qa_caret_from_end(elem) {
  if (document.selection) {
    // for IE
    elem.focus();
    var sel = document.selection.createRange();
    sel.moveStart("character", -elem.value.length);

    return elem.value.length - sel.text.length;
  } else if (typeof elem.selectionEnd != "undefined")
    // other browsers
    return elem.value.length - elem.selectionEnd;
  // by default return safest value
  else return 0;
}

function qa_tag_typed_parts(elem) {
  var caret = elem.value.length - qa_caret_from_end(elem);
  var active = elem.value.substring(0, caret);
  var passive = elem.value.substring(active.length);

  // if the caret is in the middle of a word, move the end of word from passive to active
  if (
    active.match(qa_tag_onlycomma ? /[^\s,][^,]*$/ : /[^\s,]$/) &&
    (adjoinmatch = passive.match(
      qa_tag_onlycomma ? /^[^,]*[^\s,][^,]*/ : /^[^\s,]+/
    ))
  ) {
    active += adjoinmatch[0];
    passive = elem.value.substring(active.length);
  }

  // find what has been typed so far
  var typedmatch = active.match(
    qa_tag_onlycomma ? /[^\s,]+[^,]*$/ : /[^\s,]+$/
  ) || [""];

  return {
    before: active.substring(0, active.length - typedmatch[0].length),
    after: passive,
    typed: typedmatch[0],
  };
}

function qa_category_select(idprefix, startpath) {
  var startval = startpath ? startpath.split("/") : [];
  var setdescnow = true;

  for (var l = 0; l <= qa_cat_maxdepth; l++) {
    var elem = document.getElementById(idprefix + "_" + l);

    if (elem) {
      if (l) {
        if (l < startval.length && startval[l].length) {
          var val = startval[l];

          for (var j = 0; j < elem.options.length; j++)
            if (elem.options[j].value == val) elem.selectedIndex = j;
        } else var val = elem.options[elem.selectedIndex].value;
      } else val = "";

      if (elem.qa_last_sel !== val) {
        elem.qa_last_sel = val;

        var subelem = document.getElementById(idprefix + "_" + l + "_sub");
        if (subelem) subelem.parentNode.removeChild(subelem);

        if (val.length || l == 0) {
          subelem = elem.parentNode.insertBefore(
            document.createElement("span"),
            elem.nextSibling
          );
          subelem.id = idprefix + "_" + l + "_sub";
          qa_show_waiting_after(subelem, true);

          qa_ajax_post(
            "category",
            { categoryid: val },
            (function (elem, l) {
              return function (lines) {
                var subelem = document.getElementById(
                  idprefix + "_" + l + "_sub"
                );
                if (subelem) subelem.parentNode.removeChild(subelem);

                if (lines[0] == "1") {
                  elem.qa_cat_desc = lines[1];

                  var addedoption = false;

                  if (lines.length > 2) {
                    var subelem = elem.parentNode.insertBefore(
                      document.createElement("span"),
                      elem.nextSibling
                    );
                    subelem.id = idprefix + "_" + l + "_sub";
                    subelem.innerHTML = " ";

                    var newelem = elem.cloneNode(false);

                    newelem.name = newelem.id = idprefix + "_" + (l + 1);
                    newelem.options.length = 0;

                    if (l ? qa_cat_allownosub : qa_cat_allownone)
                      newelem.options[0] = new Option(
                        l ? "" : elem.options[0].text,
                        "",
                        true,
                        true
                      );

                    for (var i = 2; i < lines.length; i++) {
                      var parts = lines[i].split("/");

                      if (
                        String(qa_cat_exclude).length &&
                        String(qa_cat_exclude) == parts[0]
                      )
                        continue;

                      newelem.options[newelem.options.length] = new Option(
                        parts.slice(1).join("/"),
                        parts[0]
                      );
                      addedoption = true;
                    }

                    if (addedoption) {
                      subelem.appendChild(newelem);
                      qa_category_select(idprefix, startpath);
                    }

                    if (l == 0) {
                      elem.style.display = "none";
                      elem.removeAttribute("required");
                    }
                  }

                  if (!addedoption) set_category_description(idprefix);
                } else if (lines[0] == "0") alert(lines[1]);
                else qa_ajax_error();
              };
            })(elem, l)
          );

          setdescnow = false;
        }

        break;
      }
    }
  }

  if (setdescnow) set_category_description(idprefix);
}

function set_category_description(idprefix) {
  var n = document.getElementById(idprefix + "_note");

  if (n) {
    desc = "";

    for (var l = 1; l <= qa_cat_maxdepth; l++) {
      var elem = document.getElementById(idprefix + "_" + l);

      if (elem && elem.options[elem.selectedIndex].value.length)
        desc = elem.qa_cat_desc;
    }

    n.innerHTML = desc;
  }
}

function video_add(item, value) {
  var params = {};
  params.url = value;
  qa_ajax_post("video_add", params, function (lines) {
    if (lines[0] == "1") {
      var x = item.parentNode;
      var y = x.querySelector("#videoembed");
      y.innerHTML = lines[1];
      console.log(x);
    }
  });
  qa_show_waiting_after(item, true);
}

function aigenerate(item) {
  var params = {};
  const input = document.getElementById("ai-box");
  const { value } = input;
  const nprompt = document.getElementById("n_prompt");
  if (nprompt) {
    var npvalue = nprompt.value;
  } else {
    var npvalue = "";
  }

  const selectedValue =
    document.querySelector('input[name="aimodel"]:checked')?.value || "";
  if (!value.trim()) {
    return;
  }
  var radioBut = $("input:radio[name=aisize]:checked").val();
  var aistyle = $("input:radio[name=aistyle]:checked").val();
  var i2iModels = ['fluxkon', 'sdream', 'banana', 'decart_img', 'luma_img', 'imagen4', 'de'];
  var newsThumb = document.getElementById('news_thumb');
  params.imageid = (newsThumb && i2iModels.indexOf(selectedValue) !== -1)
      ? (newsThumb.value || '')
      : '';

  item.disabled = true;
  input.disabled = true;
  item.classList.add("loading");
  params.input = value;
  params.npvalue = npvalue;
  params.selectElement = selectedValue;
  params.radioBut = radioBut;
  params.aistyle = aistyle;

  // Your existing code
  qa_ajax_post("aigenerate", params, function (lines) {
    const aierror = document.getElementById("ai-error");
    console.log(lines);
    if (lines[0] == "1") {
      response = JSON.parse(lines[1]);
      if (response.success) {
        input.disabled = false;
        item.disabled = false;
        item.classList.remove("loading");
        var l = document.getElementById("ai-results");
        l.innerHTML = lines.slice(2).join("\n");
      } else {
        aierror.style.display = "block";
        aierror.innerHTML += response.message;
        input.disabled = false;
        item.disabled = false;
        item.classList.remove("loading");
      }
    } else {
      response = JSON.parse(lines[1]);
      aierror.style.display = "block";
      aierror.innerHTML += response.message;
      input.disabled = false;
      item.disabled = false;
      item.classList.remove("loading");
    }
  });
}

function videogenerate(item) {
  const input = document.getElementById("ai-box");
  const value = input ? input.value.trim() : '';

  if (!value) {
    alert("Please enter a prompt before generating video.");
    return false;
  }

  console.log('🚀 Starting video generation...');

  // ✅ DISABLE UI
  item.disabled = true;
  input.disabled = true;
  item.classList.add("loading");

  // ✅ SHOW STATUS
  const statusDiv = document.getElementById("video-status");
  if (statusDiv) statusDiv.style.display = "block";

  // ✅ GET VALUES
  const imageid_el = document.getElementById("news_thumb");
  const model = document.querySelector('input[name="aimodel"]:checked')?.value || 'veo3f';
  const aisize = document.querySelector('input[name="aisize"]:checked')?.value || '16:9';
  const reso = document.querySelector('input[name="reso"]:checked')?.value || '540p';

  console.log('📋 Parameters:', {
    prompt: value.substring(0, 50),
    model: model,
    aisize: aisize,
    reso: reso
  });

  // ✅ BUILD PARAMS
  const params = {
    input: value,
    model: model,
    radio: aisize,
    reso: reso,
    imageid: imageid_el ? imageid_el.value : ''
  };

  // ✅ HIDE ERRORS
  const aierror = document.getElementById("ai-error");
  if (aierror) {
    aierror.style.display = 'none';
    aierror.innerHTML = '';
  }

  // ✅ USE OLD qa_ajax_post WITH MONKEY-PATCH FOR TIMEOUT
  console.log('📞 Calling qa_ajax_post...');
  
  // Save original XMLHttpRequest
  const OriginalXHR = window.XMLHttpRequest;
  
  // Create patched version with longer timeout
  window.XMLHttpRequest = function() {
    const xhr = new OriginalXHR();
    const originalOpen = xhr.open;
    
    xhr.open = function() {
      originalOpen.apply(xhr, arguments);
      xhr.timeout = 900000; // 15 minutes
      console.log('⏱️ Set timeout to 15 minutes');
    };
    
    return xhr;
  };

  // Now call the original qa_ajax_post with extended timeout
  qa_ajax_post("aivideo", params, function(lines) {
    console.log('📦 Response received!');
    console.log('📊 Lines array:', lines);
    console.log('📏 Lines length:', lines.length);
    
    // Log each line
    lines.forEach((line, index) => {
      console.log(`Line ${index}:`, line);
    });

    // Restore original XMLHttpRequest
    window.XMLHttpRequest = OriginalXHR;

    const success = (lines[0] == "1");
    console.log('✅ Success flag:', success);

    if (success) {
      try {
        const response = JSON.parse(lines[1]);
        console.log('📄 Parsed response:', response);

        if (response.success) {
          console.log('🎉 Video generated successfully!');

          // Update results
          const resultsDiv = document.getElementById('ai-results');
          if (resultsDiv && lines.length > 2) {
            const htmlContent = lines.slice(2).join('\n');
            console.log('📝 HTML content length:', htmlContent.length);
            resultsDiv.innerHTML = htmlContent;
          }

          // Clear input
          input.value = '';
          if (typeof adjustHeight === 'function') {
            try { adjustHeight(input); } catch(e) {}
          }

          // Generate thumbnail
          if (response.videourl && typeof generateVideoThumbnail === 'function') {
            console.log('🖼️ Generating thumbnail...');
            generateVideoThumbnail(response.videourl, function(thumbnailDataUrl) {
              qa_ajax_post('aividthumb', {
                thumb: thumbnailDataUrl,
                postid: response.postid
              }, function() {
                console.log('✅ Thumbnail saved');
              });
            });
          }

          alert('✅ Video generated successfully!');
        } else {
          console.error('❌ Response success=false:', response.message);
          throw new Error(response.message || 'Video generation failed');
        }
      } catch (parseError) {
        console.error('❌ JSON parse error:', parseError);
        console.error('❌ Raw data that failed to parse:', lines[1]);
        
        if (aierror) {
          aierror.style.display = 'block';
          aierror.innerHTML = '❌ Invalid response format: ' + parseError.message;
        }
      }
    } else {
      console.error('❌ Server returned error');
      try {
        const errorResponse = JSON.parse(lines[1]);
        console.error('❌ Error details:', errorResponse);
        
        if (aierror) {
          aierror.style.display = 'block';
          aierror.innerHTML = '❌ ' + (errorResponse.message || 'Video generation failed');
        }
      } catch (e) {
        console.error('❌ Could not parse error response');
        if (aierror) {
          aierror.style.display = 'block';
          aierror.innerHTML = '❌ Server error (could not parse response)';
        }
      }
    }

    // Re-enable UI
    if (statusDiv) statusDiv.style.display = 'none';
    input.disabled = false;
    item.disabled = false;
    item.classList.remove('loading');
  });

  return false;
}


function generateVideoThumbnail(videoUrl, callback) {
  var video = document.createElement("video");
  video.src = videoUrl;
  video.crossOrigin = "anonymous";
  video.currentTime = 1;
  video.addEventListener(
    "loadeddata",
    function () {
      var canvas = document.createElement("canvas");
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      var ctx = canvas.getContext("2d");
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      var dataURL = canvas.toDataURL("image/webp"); // Use webp format
      callback(dataURL);
    },
    { once: true }
  );
}
function deleteaipost(postId, button) {
  if (!confirm("Are you sure you want to delete this post?")) return;

  var params = {};
  params.postid = postId;

  qa_ajax_post("deleteaipost", params, function (lines) {
    if (lines[0] == "1") {
      const postElement = document.getElementById("post" + postId);
      if (postElement) {
        postElement.remove();
      }
    } else {
      alert("Error deleting the post. Please try again.");
    }
  });

  qa_show_waiting_after(button, true);
}

function aipublish(element) {
  var targetElement = document.querySelector(".ai-create"); // Corrected selector
  const uniqueid = element.getAttribute("data-id");
  targetElement.classList.toggle("active");

  var htmlElement = document.querySelector("html");
  if (htmlElement.style.marginRight === "17px") {
    htmlElement.style.marginRight = "";
    htmlElement.style.overflow = "";
  } else {
    htmlElement.style.marginRight = "17px";
    htmlElement.style.overflow = "hidden";
  }

  if (uniqueid) {
    var econ = document.getElementById("error-container");
    econ.innerHTML = "";
    var title = document.getElementById("title");
    title.value = "";
    var tags = document.getElementById("tags");
    tags.value = "";
    document.getElementById("uniqueid").value = uniqueid;
  }
}
function submitAiform(event) {
  event.preventDefault();
  var submitButton = document.getElementById("submitButton");
  submitButton.disabled = true;
  var form = document.getElementById("ai-form");
  var formData = new FormData(form);

  var xhr = new XMLHttpRequest();
  xhr.open("POST", form.action, true);

  // Set up the onload callback function
  xhr.onload = function () {
    console.log(xhr);
    if (xhr.status === 200) {
      var response = JSON.parse(xhr.responseText);
      if (response.status === "success") {
        var targetElement = document.querySelector(".ai-create");

        targetElement.classList.toggle("active");

        var htmlElement = document.querySelector("html");
        if (htmlElement.style.marginRight === "17px") {
          htmlElement.style.marginRight = "";
          htmlElement.style.overflow = "";
        } else {
          htmlElement.style.marginRight = "17px";
          htmlElement.style.overflow = "hidden";
        }

        var uniqueid = formData.get("uniqueid");
        var divElement = document.querySelector(".ai-result.p" + uniqueid);
        divElement.innerHTML =
          '<div class="ai-published"><i class="fa-solid fa-check"></i><h3>' +
          response.message +
          '</h3></div><a class="aipublish" href="' +
          response.url +
          '" target="_blank">' +
          response.message2 +
          "</a>";
        submitButton.disabled = false;
      } else {
        // Display error message at the top of the form
        var errorContainer = document.getElementById("error-container");
        errorContainer.innerHTML = "";
        for (var key in response.message) {
          if (response.message.hasOwnProperty(key)) {
            var errorMessage = response.message[key];
            errorContainer.innerHTML +=
              '<div class="king-form-tall-error">' + errorMessage + "</div>";
          }
        }
        submitButton.disabled = false;
      }
    } else {
      // Handle any errors here
      console.error("Form submission failed:", xhr.statusText);
    }
  };

  // Set up the onerror callback function
  xhr.onerror = function () {
    // Handle any network errors here
    console.error("Network error occurred");
  };

  // Send the form data
  xhr.send(formData);
}

function aipromter(elem) {
  var params = {};
  const input = document.getElementById("ai-box");
  const { value } = input;
  params.prompt = value;
  elem.disabled = true;
  elem.classList.add("loading");

  qa_ajax_post("prompter", params, function (lines) {
    var response = JSON.parse(lines[1]);
    if (response) {
      if (response.success) {
        const sresult = response.message;
        let index = 0;
        input.value = "";
        const typingInterval = setInterval(() => {
          const char = sresult.charAt(index);
          input.value += char; // Append char to textarea value
          index++;
          input.style.height = "auto"; // Reset height to auto
          input.style.height = `${input.scrollHeight}px`;
          if (index >= sresult.length) {
            clearInterval(typingInterval);
            // Enable the submit button and remove loading class
            elem.disabled = false;
            elem.classList.remove("loading");
          }
        }, 20);
      } else {
        console.log(response.data);
        input.value += response.data; // Append response data to textarea value
        // Enable the submit button and remove loading class
        elem.disabled = false;
        elem.classList.remove("loading");
      }
    }
  });
}

function adjustHeight(textarea) {
  textarea.style.height = "auto"; // Reset height to auto
  textarea.style.height = `${textarea.scrollHeight}px`; // Set the height to fit the content
}

function aiask(elem) {
  var params = {};
  const input = document.getElementById("ai-box");
  const { value } = input;
  params.prompt = value;
  elem.disabled = true;
  elem.classList.add("loading");

  qa_ajax_post("aiask", params, function (lines) {
    var response = JSON.parse(lines[1]);
    console.log(response);
    if (response) {
      if (response.success) {
        const sresult = response.message;
        const chatBox = document.querySelector("#pcontent p");
        let index = 0;
        const typingInterval = setInterval(() => {
          const char = sresult.charAt(index);
          const element = char === "\n" ? "<br>" : char;
          chatBox.insertAdjacentHTML("beforebegin", element);
          index++;
          if (index >= sresult.length) {
            clearInterval(typingInterval);
            elem.disabled = false;
            elem.classList.remove("loading");
          }
        }, 20);
      } else {
        input.value += response.data; // Append response data to textarea value
        // Enable the submit button and remove loading class
        elem.disabled = false;
        elem.classList.remove("loading");
      }
    }
  });
}

function toggleMute(videoId, btn) {
  var video = document.getElementById(videoId);
  if (video.muted) {
    video.muted = false;
    btn.innerHTML = '<i class="fa-solid fa-volume-up"></i>';
  } else {
    video.muted = true;
    btn.innerHTML = '<i class="fa-solid fa-volume-xmark"></i>';
  }
}
function togglePlayStop(videoId, btn) {
  const video = document.getElementById(videoId);
  if (video.paused || video.ended) {
    video.play();
    btn.innerHTML = '<i class="fa-solid fa-pause"></i>';
  } else {
    video.pause();
    btn.innerHTML = '<i class="fa-solid fa-play"></i>';
  }
}

// ============================================================
// AI Reference Image Upload — Dropzone for image-to-image
// ============================================================

// ============================================================
// AI Reference Image Upload — Dropzone for image-to-image
// FIXED: No persistent file input in DOM (prevents browser
//        from re-opening file dialog on page refresh)
// ============================================================

function initAIUploadZone() {
  var zone = document.getElementById('newsthumb');
  if (!zone) return;

  // Render placeholder UI
  zone.innerHTML =
      '<div class="aiupload-placeholder">' +
          '<i class="fa-solid fa-image"></i>' +
          '<span>Attach a reference image (optional)</span>' +
      '</div>';

  // ✅ FIX BUG 1: DO NOT create or append a file input at page load.
  //    Create a fresh one on every click — browser cannot restore state
  //    of an element that does not exist when the page loads.
  zone.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('.aiupload-remove')) return;

      // Create a fresh, off-screen input each time
      var fileInput = document.createElement('input');
      fileInput.type    = 'file';
      fileInput.accept  = 'image/jpeg,image/png,image/webp,image/gif';
      fileInput.setAttribute('autocomplete', 'off');
      fileInput.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;';

      fileInput.addEventListener('change', function () {
          if (this.files && this.files[0]) {
              uploadAIReferenceImage(this.files[0], zone);
          }
          // Always clean up — remove from DOM after use
          if (fileInput.parentNode) {
              fileInput.parentNode.removeChild(fileInput);
          }
      });

      // Also clean up if the dialog is cancelled (blur fires after cancel)
      fileInput.addEventListener('cancel', function () {
          if (fileInput.parentNode) {
              fileInput.parentNode.removeChild(fileInput);
          }
      });

      document.body.appendChild(fileInput);

      // Small delay ensures the DOM append is complete before triggering
      setTimeout(function () { fileInput.click(); }, 10);
  });

  // Drag over
  zone.addEventListener('dragover', function (e) {
      e.preventDefault();
      e.stopPropagation();
      zone.classList.add('aiupload-dragover');
  });

  zone.addEventListener('dragleave', function (e) {
      e.stopPropagation();
      zone.classList.remove('aiupload-dragover');
  });

  // Drop
  zone.addEventListener('drop', function (e) {
      e.preventDefault();
      e.stopPropagation();
      zone.classList.remove('aiupload-dragover');
      var files = e.dataTransfer ? e.dataTransfer.files : null;
      if (files && files[0] && files[0].type.indexOf('image/') === 0) {
          uploadAIReferenceImage(files[0], zone);
      }
  });
}


function uploadAIReferenceImage(file, zone) {
  if (!zone) zone = document.getElementById('newsthumb');
  if (!zone) return;

  // 10 MB client-side guard
  if (file.size > 10 * 1024 * 1024) {
      alert('Reference image must be under 10 MB.');
      return;
  }

  var hiddenInput = document.getElementById('news_thumb');
  if (hiddenInput) hiddenInput.value = '';

  // Show loading state
  zone.innerHTML =
      '<div class="aiupload-loading">' +
          '<i class="fa-solid fa-spinner fa-spin"></i> Uploading…' +
      '</div>';

  // Build multipart form data
  var formData = new FormData();
  formData.append('file', file);
  formData.append('qa_operation', 'aiimgupload');

  var qaRoot    = (typeof ebonix_qa_root !== 'undefined')  ? ebonix_qa_root  : '/';
  var qaRequest = 'submitai_ajax';
  if (typeof leoai !== 'undefined') {
      var paramMatch = leoai.match(/[?&]qa=([^&]+)/);
      var pathMatch  = leoai.match(/\/([^\/\?#]+)(?:[?#]|$)/);
      if (paramMatch) qaRequest = decodeURIComponent(paramMatch[1]);
      else if (pathMatch) qaRequest = decodeURIComponent(pathMatch[1]);
  }

  formData.append('qa_root',    qaRoot);
  formData.append('qa_request', qaRequest);

  var ajaxUrl = (typeof ebonix_ajax_url !== 'undefined')
      ? ebonix_ajax_url
      : (qaRoot.replace(/\/?$/, '/') + 'king-ajax.php');

  var xhr = new XMLHttpRequest();
  xhr.open('POST', ajaxUrl, true);
  xhr.timeout = 30000;

  xhr.onload = function () {
      if (xhr.status !== 200) {
          _aiUploadError(zone, 'Server error (HTTP ' + xhr.status + '). Please try again.');
          return;
      }

      var lines = xhr.responseText.split('\n');
      if (lines[0] !== 'QA_AJAX_RESPONSE') {
          _aiUploadError(zone, 'Unexpected server response. Please try again.');
          return;
      }

      if (lines[1] !== '1') {
          try {
              var errData = JSON.parse(lines[2] || '{}');
              _aiUploadError(zone, errData.message || 'Upload failed.');
          } catch (e) {
              _aiUploadError(zone, 'Upload failed. Please try again.');
          }
          return;
      }

      try {
          var data = JSON.parse(lines[2]);
          if (!data.success) throw new Error(data.message || 'Upload failed.');

          if (hiddenInput) hiddenInput.value = data.imageid;

          zone.innerHTML =
              '<div class="aiupload-preview">' +
                  '<img src="' + data.preview + '" ' +
                       'alt="Reference image" ' +
                       'style="max-height:90px;max-width:100%;border-radius:6px;object-fit:cover;">' +
                  '<button type="button" class="aiupload-remove" ' +
                          'onclick="clearAIUpload()" title="Remove image">' +
                      '<i class="fa-solid fa-xmark"></i>' +
                  '</button>' +
              '</div>';
      } catch (e) {
          _aiUploadError(zone, e.message || 'Could not process upload response.');
      }
  };

  xhr.onerror   = function () { _aiUploadError(zone, 'Network error during upload. Check your connection.'); };
  xhr.ontimeout = function () { _aiUploadError(zone, 'Upload timed out. Please try again.'); };

  xhr.send(formData);
}

function _aiUploadError(zone, msg) {
  if (!zone) return;
  zone.innerHTML =
      '<div class="aiupload-error">' +
          '<i class="fa-solid fa-circle-xmark"></i> ' + msg +
          ' <span class="aiupload-retry" onclick="clearAIUpload()" ' +
                'style="text-decoration:underline;cursor:pointer;">Try again</span>' +
      '</div>';
}

function clearAIUpload() {
  var hiddenInput = document.getElementById('news_thumb');
  if (hiddenInput) hiddenInput.value = '';

  var zone = document.getElementById('newsthumb');
  if (zone) {
      zone.innerHTML =
          '<div class="aiupload-placeholder">' +
              '<i class="fa-solid fa-image"></i>' +
              '<span>Attach a reference image (optional)</span>' +
          '</div>';
  }

  // ✅ No persistent file input to clear — we create fresh ones on each click now
}


document.addEventListener("DOMContentLoaded", function () {
  document.querySelectorAll(".custom-select").forEach((select) => {
    const btn = select.querySelector(".kings-button");
    select
      .querySelector(".king-dropdownc")
      .querySelectorAll(".cradio")
      .forEach((radio) => {
        radio.addEventListener("change", (e) => {
          e.target.checked && (btn.textContent = radio.textContent.trim());
        });
      });
  });
  // const modelRadios = document.querySelectorAll('input[name="aimodel"]'),
  //   selectedValue = document.getElementById("chclass");
  // modelRadios.length &&
  //   modelRadios.forEach((radio) => {
  //     radio.addEventListener("change", function () {
  //       if (this.checked) {
  //         (selectedValue.className = this.value),
  //           (document.getElementById("aivsize").checked = !0);
  //         var aivsizeb = document.getElementById("aivsizeb");
  //         aivsizeb && (aivsizeb.textContent = "16:9");
  //         var firstTabLink = document.querySelector("#ssize li:first-child a");
  //         firstTabLink && firstTabLink.click();
  //       }
  //     });
  //   });


  //   initAIUploadZone()



  // ============================================================
// FIND this existing block in king-ask.js (DOMContentLoaded):
//
//   const modelRadios = document.querySelectorAll('input[name="aimodel"]'),
//     selectedValue = document.getElementById("chclass");
//   modelRadios.length &&
//     modelRadios.forEach((radio) => {
//       radio.addEventListener("change", function () {
//         if (this.checked) {
//           (selectedValue.className = this.value),
//             (document.getElementById("aivsize").checked = !0);
//           var aivsizeb = document.getElementById("aivsizeb");
//           aivsizeb && (aivsizeb.textContent = "16:9");
//           var firstTabLink = document.querySelector("#ssize li:first-child a");
//           firstTabLink && firstTabLink.click();
//         }
//       });
//     });
//
//   initAIUploadZone()
//
// REPLACE that entire block with this:
// ============================================================

  // Models that support reference image upload (image-to-image)
  var I2I_MODELS = ['fluxkon', 'sdream', 'banana', 'decart_img', 'luma_img', 'imagen4', 'de'];

  function updateUploadZoneVisibility(modelValue) {
    var zone        = document.getElementById('newsthumb');
    var hiddenInput = document.getElementById('news_thumb');
    if (!zone) return;

    var supports = I2I_MODELS.indexOf(modelValue) !== -1;

    if (supports) {
      // Show upload zone
      zone.style.display = '';
    } else {
      // Hide upload zone AND clear any uploaded image
      zone.style.display = 'none';
      if (hiddenInput) hiddenInput.value = '';
      // Reset zone content so no stale preview shows when re-enabled
      zone.innerHTML =
        '<div class="aiupload-placeholder">' +
          '<i class="fa-solid fa-image"></i>' +
          '<span>Attach a reference image (optional)</span>' +
        '</div>';
    }
  }

  const modelRadios  = document.querySelectorAll('input[name="aimodel"]');
  const selectedValue = document.getElementById("chclass");

  if (modelRadios.length) {
    modelRadios.forEach(function (radio) {
      radio.addEventListener("change", function () {
        if (this.checked) {
          // Existing behaviour
          selectedValue.className = this.value;
          var aivsize = document.getElementById("aivsize");
          if (aivsize) aivsize.checked = true;
          var aivsizeb = document.getElementById("aivsizeb");
          if (aivsizeb) aivsizeb.textContent = "16:9";
          var firstTabLink = document.querySelector("#ssize li:first-child a");
          if (firstTabLink) firstTabLink.click();

          // ✅ NEW: show/hide upload zone based on model
          updateUploadZoneVisibility(this.value);
        }
      });
    });

    // ✅ NEW: set correct visibility on page load for default selected model
    var defaultModel = document.querySelector('input[name="aimodel"]:checked');
    if (defaultModel) {
      updateUploadZoneVisibility(defaultModel.value);
    }
  }

  initAIUploadZone();

  });

$(document).ready(function () {
  $(document).magnificPopup({
    delegate: '.king-aipost-left a',
    type: 'image',
    closeOnContentClick: false,
    closeBtnInside: false,
    mainClass: 'king-gallery-zoom',
    gallery: { enabled: true },
    zoom: {
      enabled: true,
      duration: 300,
      opener: function (element) {
        return element.is('img') ? element : element.find('img');
      }
    }
  });
});