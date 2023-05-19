
FOR /F "tokens=* USEBACKQ" %%L IN (`docker container ls -q --filter "name=x100-*"`) DO (
    docker container stop %%L
    docker rm %%L
)

FOR /F "tokens=* USEBACKQ" %%L IN (`docker image ls -q "ihorlv/x100*"`) DO (
    docker image rm --force %%L
)