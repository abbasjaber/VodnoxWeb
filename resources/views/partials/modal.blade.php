<dialog id="modal">
    <div class="modal-head">
        <div><small id="modalKicker">VODNOX</small><h2 id="modalTitle"></h2></div>
        <button class="icon-btn" id="closeModal" aria-label="@lang('vodnox.close')">&times;</button>
    </div>
    <form id="modalForm">
        <div id="modalBody"></div>
        <div class="modal-foot">
            <button type="button" class="btn-quiet" id="cancelModal" data-t="cancel">@lang('vodnox.cancel')</button>
            <button type="submit" class="btn-primary" id="modalSubmit">@lang('vodnox.save')</button>
        </div>
    </form>
</dialog>
