# mp4salvage

Salvaging a mp4 file with some PHP.

A friend of mine had an issue with his camera, resulting in a RSV file where he expected a MP4 file.

Some research showed that this happens with Sony cameras. I had him send me a correct file generated by the same camera and looked into it.

I found that the RSV file contains the MP4 file `mdat` entry as is. Online documentation showed this as being likely hopeless, except that [one blog article mentioning the `kkad` atom](https://aeroquartet.com/wordpress/2016/03/05/3-xavc-s/) as having a duplication of the MP4 data and being meaningless.

My guess is that the Sony hardware generates the video data we get in the RSV file, and the firmware uses the data inside to generate the MP4 container based on the data found in `kkad`.

I didn't understand all the data found in there but I found that:

* The `kkad` atom can be split in multiple `rtmd` frames
* `mdat` data has chunks in `rtmd`, `video`, `audio` order
* the `kkad` atom contains the length of the video frames in little endian order

From there, decoding the whole `mdat` was fairly simple, as long as I could know how long was each video frame and know how many frames per chunk I was expecting, it turned to be fairly simple. The main difficulty was to find a way to feed back the h264 data to a decoder. I ended generating a new mp4 file based on the existing one, which meant dealing with some specificities of mp4 (such as handling of 64bit offets) but turned out to work fairly well.

In the end this was an interesting dig into MP4 file format, which I had to do anyway since I've been working on HLS.

Feel free to try to use & adapt if you have the same issue. The file here is made for what I found (12 video/rtmd frames per chunk, etc) so adaptation is needed (or it could be taken from the good file). Good luck.
