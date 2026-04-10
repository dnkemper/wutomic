#!/bin/bash
# mv web/sites/default/files/public: web/sites/default/files/public
# List of style names
styles=(
    "background_image"
    "blog_horizontal_thumbnail"
    "book_cover"
    "card"
    "default"
    "event_header"
    "focal_point_preview"
    "fullscreen_video"
    "infographic"
    "landscape_16_9_320w"
    "landscape_16_9_448w"
    "landscape_16_9_944w"
    "landscape_16_9_1888w"
    "media_library"
    "media_thumbnail"
    "no_crop_0190w"
    "no_crop_0320w"
    "no_crop_0448w"
    "no_crop_0768w"
    "no_crop_1024w"
    "no_crop_1888w"
    "portrait_2_3_320w"
    "portrait_2_3_448w"
    "portrait_2_3_944w"
    "portrait_2_3_1888w"
    "square_1_1_190w"
    "square_1_1_320w"
    "square_1_1_480w"
    "square_1_1_680w"
    "square_1_1_944w"
    "square_1_1_1888w"
)

do_generate=1
if [ $1 == "--gen" ]; then
  do_generate=0
fi

if [ do_generate ]; then
   echo "Generating files for image styles..."

  for style in "${styles[@]}"; do
    echo ">>>> style: ${style}"
    ddev drush image_derivatives:generate --image_styles=${style}
  done

  # Another possibility is to generate the images for
  # all the styles with a single call to image_derivatives.
  #
  # Collect parameter list for image_derivatives
  # style_parms=""
  # for style in "${styles[@]}"; do
  #   if [ -z "${style_parms}" ]; then
  #     style_parms="${style}"
  #   else
  #     style_parms="${style_parms},${style}"
  #   fi
  # done
  # Generate the images
  # ddev drush image_derivatives:generate --image_styles=${style_parms}

  echo "All image styles generated."
else

  # Base directory path
  base_dir="web/sites/default/files/styles"

  # Source directory to copy
  source_dir="web/sites/default/files/public"

  # Create directories and copy the source directory to each
  for style in "${styles[@]}"; do
      target_dir="${base_dir}/${style}"

      # Create the directory
      mkdir -p "$target_dir"

      # Copy the source directory to the target directory
      cp -r "$source_dir" "$target_dir"

      echo "Created and copied to $target_dir"
  done

  echo "All directories created and files copied."
fi
