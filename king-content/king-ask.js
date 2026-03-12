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

























































  function aiEscapeHtml(text) {
    return String(text == null ? '' : text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function getAIUploadEndpoint() {
    if (typeof window.EBONIX_UPLOAD_URL === 'string' && window.EBONIX_UPLOAD_URL.trim()) {
      return window.EBONIX_UPLOAD_URL.trim();
    }

    if (typeof window.ebonix_ajax_url === 'string' && window.ebonix_ajax_url.trim()) {
      return window.ebonix_ajax_url.trim();
    }

    if (typeof window.qa_root === 'string' && window.qa_root.length > 0) {
      var base = window.qa_root.replace(/\/+$/, '');
      return (base ? base : '') + '/king-include/king-ajax.php';
    }

    return window.location.origin + '/king-include/king-ajax.php';
  }

  function renderAIUploadPlaceholder(zone) {
    if (!zone) return;

    zone.innerHTML =
      '<div class="aiupload-placeholder">' +
        '<i class="fa-solid fa-image"></i>' +
        '<span>Attach a reference image (optional)</span>' +
      '</div>';
  }

  function _aiUploadError(zone, msg) {
    if (!zone) return;

    zone.innerHTML =
      '<div class="aiupload-error">' +
        '<i class="fa-solid fa-circle-xmark"></i> ' + aiEscapeHtml(msg || 'Upload failed.') +
        ' <span class="aiupload-retry" onclick="clearAIUpload()" style="text-decoration:underline;cursor:pointer;">Try again</span>' +
      '</div>';
  }



  function openAIUploadPicker(zone) {
    var fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = 'image/jpeg,image/png,image/webp,image/gif';
    fileInput.autocomplete = 'off';
    fileInput.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0;pointer-events:none;';

    function cleanup() {
      if (fileInput && fileInput.parentNode) {
        fileInput.parentNode.removeChild(fileInput);
      }
    }

    fileInput.addEventListener('change', function () {
      if (fileInput.files && fileInput.files[0]) {
        uploadAIReferenceImage(fileInput.files[0], zone);
      }
      cleanup();
    });

    fileInput.addEventListener('blur', function () {
      setTimeout(cleanup, 300);
    });

    document.body.appendChild(fileInput);
    setTimeout(function () {
      fileInput.click();
    }, 10);
  }

  function parseQAUploadResponse(raw) {
    raw = String(raw || '').replace(/\r/g, '');

    var tokenIdx = raw.indexOf('QA_AJAX_RESPONSE');
    if (tokenIdx === -1) {
      return {
        ok: false,
        message: 'Unexpected server response.',
        raw: raw
      };
    }

    var cleaned = raw.substring(tokenIdx);
    var lines = cleaned.split('\n');
    var statusLine = (lines[1] || '').trim();
    var payloadLine = (lines[2] || '').trim();

    if (statusLine !== '1') {
      var errMsg = 'Upload failed.';

      if (payloadLine) {
        try {
          var errObj = JSON.parse(payloadLine);
          if (errObj && errObj.message) {
            errMsg = errObj.message;
          } else {
            errMsg = payloadLine;
          }
        } catch (e) {
          errMsg = payloadLine;
        }
      }

      return {
        ok: false,
        message: errMsg,
        raw: raw
      };
    }

    try {
      var data = JSON.parse(payloadLine || '{}');

      if (!data.success || !data.imageid || !data.preview) {
        return {
          ok: false,
          message: data.message || 'Upload response is incomplete.',
          raw: raw
        };
      }

      return {
        ok: true,
        data: data,
        raw: raw
      };
    } catch (e) {
      return {
        ok: false,
        message: 'Could not process upload response.',
        raw: raw
      };
    }
  }

  function uploadAIReferenceImage(file, zone) {
    if (!zone) zone = document.getElementById('newsthumb');
    if (!zone) return;

    if (!(file instanceof File)) {
      _aiUploadError(zone, 'Invalid file selected.');
      return;
    }

    var allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (allowedTypes.indexOf(file.type) === -1) {
      _aiUploadError(zone, 'Only JPEG, PNG, WebP, and GIF files are allowed.');
      return;
    }

    if (file.size > 10 * 1024 * 1024) {
      _aiUploadError(zone, 'Reference image must be under 10 MB.');
      return;
    }

    var hiddenInput = document.getElementById('news_thumb');
    if (hiddenInput) hiddenInput.value = '';

    zone.innerHTML =
      '<div class="aiupload-loading">' +
        '<i class="fa-solid fa-spinner fa-spin"></i> Uploading…' +
      '</div>';

    var ajaxUrl = getAIUploadEndpoint();
    console.log('[AI upload] Posting to:', ajaxUrl);

    var formData = new FormData();
    formData.append('qa_operation', 'aiimgupload');
    formData.append('qa_request', 'submitai');
    formData.append('qa_root', (typeof window.qa_root === 'string' && window.qa_root) ? window.qa_root : '/');
    formData.append('file', file);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', ajaxUrl, true);
    xhr.timeout = 60000;
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'text/plain');

    xhr.onload = function () {
      var raw = xhr.responseText || '';

      if (xhr.status < 200 || xhr.status >= 300) {
        console.error('[AI upload] HTTP error:', xhr.status, ajaxUrl);
        console.error('[AI upload] Raw response:', raw.substring(0, 1000));

        var shortMsg = 'Server error (HTTP ' + xhr.status + '). Please try again.';
        if (raw) {
          var trimmed = raw.replace(/\s+/g, ' ').trim();
          if (trimmed) {
            shortMsg = 'Server error (HTTP ' + xhr.status + ').';
          }
        }

        _aiUploadError(zone, shortMsg);
        return;
      }

      var parsed = parseQAUploadResponse(raw);

      if (!parsed.ok) {
        console.error('[AI upload] Response error:', parsed.message);
        console.error('[AI upload] Raw response:', parsed.raw ? parsed.raw.substring(0, 1000) : '');
        _aiUploadError(zone, parsed.message || 'Upload failed.');
        return;
      }

      var data = parsed.data;

      if (hiddenInput) hiddenInput.value = data.imageid;

      zone.innerHTML =
        '<div class="aiupload-preview">' +
          '<img src="' + aiEscapeHtml(data.preview) + '" alt="Reference image" style="max-height:90px;max-width:100%;border-radius:6px;object-fit:cover;">' +
          '<button type="button" class="aiupload-remove" onclick="clearAIUpload();return false;" title="Remove image">' +
            '<i class="fa-solid fa-xmark"></i>' +
          '</button>' +
        '</div>';

      try {
        document.dispatchEvent(new Event('aiUploadComplete'));
      } catch (e) {}
    };

    xhr.onerror = function () {
      console.error('[AI upload] Network error posting to:', ajaxUrl);
      _aiUploadError(zone, 'Network error during upload. Please try again.');
    };

    xhr.ontimeout = function () {
      console.error('[AI upload] Timeout posting to:', ajaxUrl);
      _aiUploadError(zone, 'Upload timed out. Please try again.');
    };

    try {
      xhr.send(formData);
    } catch (e) {
      console.error('[AI upload] Send failed:', e);
      _aiUploadError(zone, 'Could not start upload. Please try again.');
    }
  }


































































  function aigenerate(item) {
    var I2I_MODELS    = (typeof EBONIX_I2I_MODELS    !== 'undefined') ? EBONIX_I2I_MODELS    : ['fluxkon','sdream','banana','decart_img','luma_img','imagen4','de','fluxkon_selfie'];
    var SELFIE_MODELS = (typeof EBONIX_SELFIE_MODELS !== 'undefined') ? EBONIX_SELFIE_MODELS : ['fluxkon_selfie'];

    var selectedModel = (document.querySelector('input[name="aimodel"]:checked') || {}).value || '';
    var isI2I         = I2I_MODELS.indexOf(selectedModel)    !== -1;
    var isSelfie      = SELFIE_MODELS.indexOf(selectedModel) !== -1;

    var input   = document.getElementById('ai-box');
    var value   = input ? input.value.trim() : '';
    var aierror = document.getElementById('ai-error');
    var results = document.getElementById('ai-results');

    function showErr(msg) {
        if (aierror) { aierror.textContent = msg; aierror.style.display = 'block'; }
    }
    function hideErr() {
        if (aierror) { aierror.style.display = 'none'; aierror.textContent = ''; }
    }
    function setLoading(on) {
        item.disabled = on;
        if (input) input.disabled = on;
        if (on) item.classList.add('loading');
        else    item.classList.remove('loading');
    }

    hideErr();

    // Non-selfie models require a prompt
    if (!isSelfie && !value) return false;

    // Check for reference image
    var refInput = document.getElementById('ref_image');
    var hasFile  = !!(refInput && refInput.files && refInput.files[0]);

    // Selfie model requires a photo
    if (isSelfie && !hasFile) {
        showErr('Please attach a photo above before generating.');
        var hint = document.getElementById('selfie-upload-hint');
        if (hint) hint.style.display = 'flex';
        return false;
    }

    // Validate file when present
    if (hasFile) {
        var f       = refInput.files[0];
        var allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (allowed.indexOf(f.type) === -1) {
            showErr('Only JPEG, PNG, WebP, or GIF images are allowed.');
            return false;
        }
        if (f.size > 10 * 1024 * 1024) {
            showErr('Image must be under 10 MB.');
            return false;
        }
    }

    var npvalue  = (document.getElementById('n_prompt') || {}).value || '';
    var radioBut = (document.querySelector('input[name="aisize"]:checked')  || {}).value || '1024x1024';
    var aistyle  = (document.querySelector('input[name="aistyle"]:checked') || {}).value || '';

    var ajaxUrl = (typeof window.EBONIX_UPLOAD_URL === 'string' && window.EBONIX_UPLOAD_URL.trim())
                ? window.EBONIX_UPLOAD_URL.trim()
                : ((typeof window.leoai === 'string' && window.leoai.trim())
                ? window.leoai.trim()
                : window.location.origin + '/king-include/king-ajax.php');

    var qaRoot = (typeof window.ebonix_qa_root === 'string' && window.ebonix_qa_root)
              ? window.ebonix_qa_root : '/';

    setLoading(true);

    /* ── Build FormData and send via XHR ────────────────────────────────── */
    function doPost(b64DataUri) {
        var fd = new FormData();
        fd.append('qa_operation',  'aigenerate');
        fd.append('qa_request',    'submitai');
        fd.append('qa_root',       qaRoot);
        fd.append('input',         value || (isSelfie ? 'enhance this photo' : ''));
        fd.append('selectElement', selectedModel);
        fd.append('radioBut',      radioBut);
        fd.append('aistyle',       aistyle);
        fd.append('npvalue',       npvalue);
        fd.append('imageid',       ''); // always empty — image travels as ref_image_b64

        if (b64DataUri) {
            // "data:image/jpeg;base64,/9j/..." — aigenerate.php strips the prefix
            fd.append('ref_image_b64', b64DataUri);
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.timeout = 660000; // 11 min — enough for Fal AI (up to 7.5 min) + gateway

        xhr.onload = function () {
            setLoading(false);
            if (xhr.status < 200 || xhr.status >= 300) {
                showErr('Server error (HTTP ' + xhr.status + '). Please try again.');
                return;
            }
            _aigenerate_handle_response(xhr.responseText, results, showErr, hideErr);
        };

        xhr.onerror = function () {
            setLoading(false);
            showErr('Network error. Please check your connection and try again.');
        };

        xhr.ontimeout = function () {
            setLoading(false);
            showErr('Request timed out. AI transformation can take 1–2 minutes. Please try again.');
        };

        try {
            xhr.send(fd);
        } catch (e) {
            setLoading(false);
            showErr('Could not send request: ' + (e.message || String(e)));
        }
    }

    /* ── Read file as base64 when an image is attached ──────────────────── */
    if (isI2I && hasFile) {
        var reader = new FileReader();
        reader.onerror = function () {
            setLoading(false);
            showErr('Could not read image file. Please try again.');
        };
        reader.onload = function (ev) {
            doPost(ev.target.result); // full data-URI: "data:image/jpeg;base64,..."
        };
        reader.readAsDataURL(refInput.files[0]);
    } else {
        // No image — text-only generation
        doPost(null);
    }

    return false;
  }


  /* ============================================================================
  * 2. RESPONSE PARSER  (new helper — add this function alongside aigenerate)
  *
  * Parses the QA_AJAX_RESPONSE wire format that aigenerate.php returns:
  *
  *   QA_AJAX_RESPONSE\n
  *   1\n                          ← status ("1" = ok, "0" = error)
  *   {"success":true,...}\n       ← JSON payload
  *   <div class="ai-result">...   ← rendered HTML (one or more lines)
  * ============================================================================ */
  function _aigenerate_handle_response(raw, results, showErr, hideErr) {
    raw = String(raw || '');

    var markerIdx = raw.indexOf('QA_AJAX_RESPONSE');
    if (markerIdx === -1) {
        showErr('Unexpected response from server. Check error logs.');
        console.error('[aigenerate] No QA_AJAX_RESPONSE marker. Raw:', raw.substring(0, 500));
        return;
    }

    // Skip past "QA_AJAX_RESPONSE\n"
    var after  = raw.substring(markerIdx + 'QA_AJAX_RESPONSE'.length + 1);
    var lines  = after.split('\n');
    var status = (lines[0] || '').trim(); // "1" or "0"
    var json   = (lines[1] || '').trim(); // JSON payload

    if (status === '1') {
        var response;
        try {
            response = JSON.parse(json);
        } catch (e) {
            showErr('Could not parse server response.');
            console.error('[aigenerate] JSON parse error:', e, 'json:', json);
            return;
        }

// FIND this entire block inside _aigenerate_handle_response:
//
//      if (response.success) {
//          hideErr();
//          if (results) {
//              var html = lines.slice(2).join('\n').trim();
//              if (html) {
//                  // Prepend newest result so it appears at the top
//                  results.innerHTML = html + results.innerHTML;
//                  results.scrollIntoView({ behavior: 'smooth', block: 'start' });
//              }
//          }
//      }
//
// REPLACE with:

if (response.success) {
  hideErr();
  if (results) {
      var html = lines.slice(2).join('\n').trim();
      if (html) {
          // Prepend newest result so it appears at the top
          results.innerHTML = html + results.innerHTML;

          // ── Force-load lazy images immediately after inject ──────
          // The lazy-load library won't re-scan injected HTML on its
          // own, so we swap data-src → src right here, before the
          // browser has a chance to render broken images.
          (function forceLoadNew(container) {
              // Standard data-src pattern
              container.querySelectorAll('img[data-src]').forEach(function (img) {
                  var ds = img.getAttribute('data-src');
                  if (ds) img.src = ds;
              });
              // WP / some themes use data-lazy-src
              container.querySelectorAll('img[data-lazy-src]').forEach(function (img) {
                  var ds = img.getAttribute('data-lazy-src');
                  if (ds) img.src = ds;
              });
              // Catch-all: any img whose src is missing/empty but dataset.src exists
              container.querySelectorAll('img').forEach(function (img) {
                  if (img.dataset && img.dataset.src) {
                      if (!img.src || img.src === window.location.href || img.naturalWidth === 0) {
                          img.src = img.dataset.src;
                      }
                  }
              });
              // Refresh global lazy-load library instances if present
              if (window.lazyLoadInstance && typeof window.lazyLoadInstance.update === 'function') {
                  window.lazyLoadInstance.update();
              }
              if (typeof jQuery !== 'undefined') {
                  try { jQuery(container).find('img.lazy,img.lazyload').trigger('appear'); } catch (e) {}
              }
          }(results));
          // ── end lazy-load fix ────────────────────────────────────

          results.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
  }
} else {
            showErr(response.message || 'Generation failed. Please try again.');
        }

    } else {
        // Status "0" — server-side error
        var msg = 'Generation failed. Please try again.';
        try {
            var errObj = JSON.parse(json);
            if (errObj && errObj.message) msg = errObj.message;
        } catch (e) {
            if (json) msg = json;
        }
        showErr(msg);
        console.error('[aigenerate] Server returned error. json:', json);
    }
  }


  /* ============================================================================
  * 3. SELFIE GENERATION
  *
  * Old version tried to duplicate the entire generation logic and had a
  * fatal bug: it called ebonixGenerateSelfie(btn) from inside aigenerate()
  * where the variable was named `item`, so `btn` was always undefined.
  *
  * New version: just delegate to aigenerate(). The rewritten aigenerate()
  * already handles selfie models correctly — reads the file as base64,
  * validates that a photo is attached, and posts ref_image_b64.
  * ============================================================================ */
  function ebonixGenerateSelfie(btn) {
    return aigenerate(btn);
  }


  /* ============================================================================
  * 4. UPLOAD ZONE INIT
  *
  * Critical fix: the old version called renderAIUploadPlaceholder(zone) which
  * wipes the innerHTML of #newsthumb, destroying the <input type="file"> that
  * submitai.php placed there. We now detect the new structure and skip.
  * ============================================================================ */
  function initAIUploadZone() {
    var zone = document.getElementById('newsthumb');
    if (!zone) return;

    // ── New architecture detection ────────────────────────────────────────────
    // submitai.php places a real <input id="ref_image" type="file"> inside
    // #newsthumb. If we find it, DO NOT reinitialise — the PHP page manages
    // the file input directly and any reinit would destroy it.
    if (zone.querySelector('#ref_image')) {
        return; // new structure — nothing to do here
    }

    // ── Legacy behaviour (kept for any other page that uses the old zone) ────
    if (zone.getAttribute('data-aiupload-init') === '1') return;
    zone.setAttribute('data-aiupload-init', '1');
    renderAIUploadPlaceholder(zone);

    zone.addEventListener('click', function (e) {
        if (zone.querySelector('.aiupload-preview') ||
            zone.querySelector('.aiupload-loading') ||
            (e.target && e.target.closest('.aiupload-remove'))) {
            return;
        }
        openAIUploadPicker(zone);
    });

    zone.addEventListener('dragover', function (e) {
        e.preventDefault(); e.stopPropagation();
        zone.classList.add('aiupload-dragover');
    });

    zone.addEventListener('dragleave', function (e) {
        e.preventDefault(); e.stopPropagation();
        zone.classList.remove('aiupload-dragover');
    });

    zone.addEventListener('drop', function (e) {
        e.preventDefault(); e.stopPropagation();
        zone.classList.remove('aiupload-dragover');
        var files = e.dataTransfer ? e.dataTransfer.files : null;
        if (!files || !files[0]) return;
        uploadAIReferenceImage(files[0], zone);
    });
  }


  /* ============================================================================
  * 5. CLEAR UPLOAD
  *
  * Old version called renderAIUploadPlaceholder() which destroyed the file
  * input. New version delegates to clearRefImage() (defined in submitai.php's
  * inline script) or directly resets the inputs if running elsewhere.
  * ============================================================================ */
  function clearAIUpload() {
    // ── New architecture: delegate to submitai.php's inline clearRefImage() ──
    if (typeof clearRefImage === 'function') {
        clearRefImage();
        return;
    }

    // ── Fallback: reset inputs directly ──────────────────────────────────────
    var fi = document.getElementById('ref_image');
    if (fi) fi.value = '';

    var nt = document.getElementById('news_thumb');
    if (nt) nt.value = '';

    var lbl = document.getElementById('ref-image-filename');
    if (lbl) lbl.textContent = 'Attach a reference image (optional)';

    var cb = document.getElementById('ref-image-clear');
    if (cb) cb.style.display = 'none';

    var hint = document.getElementById('selfie-upload-hint');
    if (hint) {
        var cur = (document.querySelector('input[name="aimodel"]:checked') || {}).value || '';
        var selfieModels = (typeof EBONIX_SELFIE_MODELS !== 'undefined') ? EBONIX_SELFIE_MODELS : [];
        hint.style.display = (selfieModels.indexOf(cur) !== -1) ? 'flex' : 'none';
    }

    // ── Legacy zone fallback (for pages that still use the old dropzone) ──────
    var zone = document.getElementById('newsthumb');
    if (zone && !zone.querySelector('#ref_image')) {
        zone.classList.remove('aiupload-dragover');
        renderAIUploadPlaceholder(zone);
    }
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
    
    var I2I_MODELS = (typeof EBONIX_I2I_MODELS !== 'undefined')
    ? EBONIX_I2I_MODELS
    : ['fluxkon', 'sdream', 'banana', 'decart_img', 'luma_img', 'imagen4', 'de', 'fluxkon_selfie'];

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